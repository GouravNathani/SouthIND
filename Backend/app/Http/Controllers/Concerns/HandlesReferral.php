<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\ReferralSettingRequest;
use App\Models\CommissionEntry;
use App\Models\CommissionPayout;
use App\Models\ReferralAudit;
use App\Models\User;
use App\Support\Referral\CommissionLedger;
use App\Support\Referral\ReferralCode;
use App\Support\Referral\ReferralPresenter;
use App\Support\Referral\ReferralReports;
use App\Support\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The whole referral admin surface, written once against an explicit branch id.
 *
 * The Admin controller resolves that branch from the acting admin; the Super
 * controller takes it from a query parameter. Keeping the bodies here means a
 * rule fixed in one panel is fixed in both — this module moves money, so the
 * two panels drifting apart is a real risk, not a style concern.
 */
trait HandlesReferral
{
    abstract protected function branchIdFor(Request $request): int;

    protected function referral(): ReferralService
    {
        return app(ReferralService::class);
    }

    // ---- Overview & settings -------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $branchId = $this->branchIdFor($request);

        return response()->json(['data' => [
            'branch_id' => $branchId,
            'overview' => ReferralReports::overview($branchId),
            'settings' => ReferralPresenter::settings($this->referral()->settings($branchId)),
            'leaderboard' => ReferralReports::leaderboard($branchId),
        ]]);
    }

    public function updateSettings(ReferralSettingRequest $request): JsonResponse
    {
        $branchId = $this->branchIdFor($request);

        $settings = $this->referral()->updateSettings(
            $branchId,
            $request->validated(),
            $request->user()->id,
        );

        return response()->json(['data' => ReferralPresenter::settings($settings)]);
    }

    // ---- Agents ---------------------------------------------------------

    public function agents(Request $request): JsonResponse
    {
        $branchId = $this->branchIdFor($request);

        $paginator = ReferralReports::agents($branchId, [
            'search' => $request->query('search'),
            'agent_status' => $request->query('agent_status'),
            'sort' => $request->query('sort'),
        ], min(100, max(5, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (User $agent) => ReferralPresenter::agent($agent))
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function agent(Request $request, User $user): JsonResponse
    {
        $branchId = $this->branchIdFor($request);
        $this->assertSameBranch($user, $branchId);

        CommissionLedger::refreshTeamStats((int) $user->id);

        $entries = CommissionEntry::query()
            ->where('agent_id', $user->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json(['data' => [
            'agent' => ReferralPresenter::agent($user->fresh()),
            'team' => ReferralReports::team($user, forAdmin: true),
            'entries' => $entries->map(fn ($entry) => ReferralPresenter::entryForAdmin($entry))->all(),
            'payouts' => CommissionPayout::query()
                ->where('agent_id', $user->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn ($payout) => ReferralPresenter::payout($payout))
                ->all(),
        ]]);
    }

    /** Promote / demote / suspend / resume, plus the per-agent rate override. */
    public function updateAgent(Request $request, User $user): JsonResponse
    {
        $branchId = $this->branchIdFor($request);
        $this->assertSameBranch($user, $branchId);

        $data = $request->validate([
            'user_type' => ['sometimes', 'string', 'in:' . implode(',', User::TYPES)],
            'agent_status' => ['sometimes', 'string', 'in:' . implode(',', User::AGENT_STATUSES)],
            'commission_percent_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $adminId = $request->user()->id;
        $reason = $data['reason'] ?? null;

        try {
            if (array_key_exists('user_type', $data)) {
                $user = $data['user_type'] === User::TYPE_AGENT
                    ? $this->referral()->promote($user, $adminId)
                    : $this->referral()->demote($user, $adminId, $reason);
            }

            if (array_key_exists('agent_status', $data)) {
                $user = $this->referral()->setAgentStatus($user, $data['agent_status'], $adminId, $reason);
            }

            if (array_key_exists('commission_percent_override', $data)) {
                $percent = $data['commission_percent_override'];
                $user = $this->referral()->setOverrideRate(
                    $user,
                    $percent === null ? null : (float) $percent,
                    $adminId,
                );
            }
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ReferralPresenter::agent($user->fresh())]);
    }

    /** Rotate a leaked code. The old one stops resolving immediately. */
    public function regenerateCode(Request $request, User $user): JsonResponse
    {
        $branchId = $this->branchIdFor($request);
        $this->assertSameBranch($user, $branchId);

        $user->referral_code = ReferralCode::generate();
        $user->save();

        ReferralAudit::record(
            branchId: $branchId,
            userId: (int) $user->id,
            action: ReferralAudit::ACTION_SETTINGS,
            actorId: $request->user()->id,
            reason: 'Referral code regenerated.',
        );

        return response()->json(['data' => ReferralPresenter::agent($user->fresh())]);
    }

    // ---- Attribution ----------------------------------------------------

    /**
     * Connect a user to their referrer. Accepts either the agent's id or their
     * referral code — admins mostly have the code, from a screenshot.
     */
    public function attach(Request $request): JsonResponse
    {
        $branchId = $this->branchIdFor($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'referrer_id' => ['required_without:referral_code', 'nullable', 'integer'],
            'referral_code' => ['required_without:referrer_id', 'nullable', 'string', 'max:16'],
            'retroactive' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $user = User::query()->find($data['user_id']);

        if (!$user || (int) $user->branch_id !== $branchId) {
            return response()->json(['message' => 'User not found in this branch.'], Response::HTTP_NOT_FOUND);
        }

        $referrer = filled($data['referrer_id'] ?? null)
            ? User::query()->where('branch_id', $branchId)->find($data['referrer_id'])
            : ReferralCode::resolve($data['referral_code'] ?? null, $branchId);

        if (!$referrer) {
            return response()->json(['message' => 'Referrer not found in this branch.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $user = $this->referral()->attach(
                user: $user,
                referrer: $referrer,
                adminId: $request->user()->id,
                reason: $data['reason'] ?? null,
                retroactive: array_key_exists('retroactive', $data) ? (bool) $data['retroactive'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => [
            'user' => ReferralPresenter::teamMemberForAdmin($user, 0, 0),
            'referrer' => ReferralPresenter::agent($referrer->fresh()),
        ]]);
    }

    public function detach(Request $request, User $user): JsonResponse
    {
        $branchId = $this->branchIdFor($request);
        $this->assertSameBranch($user, $branchId);

        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $user = $this->referral()->detach($user, $request->user()->id, $data['reason'] ?? null);

        return response()->json(['data' => ReferralPresenter::teamMemberForAdmin($user, 0, 0)]);
    }

    // ---- Ledger ----------------------------------------------------------

    public function entries(Request $request): JsonResponse
    {
        $branchId = $this->branchIdFor($request);

        $query = CommissionEntry::query()
            ->where('branch_id', $branchId)
            ->with('agent:id,name,play_id,unique_number');

        if (filled($request->query('agent_id'))) {
            $query->where('agent_id', (int) $request->query('agent_id'));
        }

        if (filled($request->query('type'))) {
            $query->where('type', $request->query('type'));
        }

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        // The review queue: accruals whose deposit no longer stands.
        if ($request->boolean('flagged')) {
            $query->needsReview();
        }

        if (filled($request->query('from'))) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }

        if (filled($request->query('to'))) {
            $query->whereDate('created_at', '<=', $request->query('to'));
        }

        $paginator = $query->orderByDesc('id')
            ->paginate(min(100, max(5, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($entry) => ReferralPresenter::entryForAdmin($entry))
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'flagged_total' => CommissionEntry::query()->where('branch_id', $branchId)->needsReview()->count(),
            ],
        ]);
    }

    /**
     * The self-adjust tool. Positive credits the agent, negative debits them —
     * this is how a commission on a later-reversed deposit is taken back, by a
     * person, on the record.
     */
    public function adjust(Request $request): JsonResponse
    {
        $branchId = $this->branchIdFor($request);

        $data = $request->validate([
            'agent_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'not_in:0', 'min:-9999999', 'max:9999999'],
            'note' => ['required', 'string', 'max:500'],
            'against_entry_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $agent = User::query()->where('branch_id', $branchId)->find($data['agent_id']);

        if (!$agent) {
            return response()->json(['message' => 'Agent not found in this branch.'], Response::HTTP_NOT_FOUND);
        }

        $against = null;

        if (filled($data['against_entry_id'] ?? null)) {
            $against = CommissionEntry::query()
                ->where('branch_id', $branchId)
                ->find($data['against_entry_id']);

            if (!$against) {
                return response()->json(['message' => 'Entry not found in this branch.'], Response::HTTP_NOT_FOUND);
            }
        }

        try {
            $entry = $this->referral()->adjust(
                agent: $agent,
                amount: (float) $data['amount'],
                note: $data['note'],
                adminId: $request->user()->id,
                against: $against,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ReferralPresenter::entryForAdmin($entry)]);
    }

    /** "I checked it, the credit is fine" — clears the flag, moves no money. */
    public function resolveEntry(Request $request, CommissionEntry $entry): JsonResponse
    {
        $branchId = $this->branchIdFor($request);

        if ((int) $entry->branch_id !== $branchId) {
            return response()->json(['message' => 'Entry not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $entry = $this->referral()->resolveFlag($entry, $request->user()->id, $data['note'] ?? null);

        return response()->json(['data' => ReferralPresenter::entryForAdmin($entry)]);
    }

    // ---- Payouts ----------------------------------------------------------

    public function payouts(Request $request): JsonResponse
    {
        $branchId = $this->branchIdFor($request);

        $query = CommissionPayout::query()
            ->where('branch_id', $branchId)
            ->with('agent:id,name,phone,play_id,unique_number');

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        if (filled($request->query('agent_id'))) {
            $query->where('agent_id', (int) $request->query('agent_id'));
        }

        $paginator = $query->orderByDesc('id')
            ->paginate(min(100, max(5, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($payout) => ReferralPresenter::payout($payout))
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Pay an agent in advance — no request from them, no balance requirement.
     * Overpaying is allowed on purpose and drives the account negative.
     */
    public function advancePayout(Request $request): JsonResponse
    {
        $branchId = $this->branchIdFor($request);

        $data = $request->validate([
            'agent_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'method' => ['required', 'string', 'in:' . implode(',', CommissionPayout::METHODS)],
            'upi_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'ifsc_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'account_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['required', 'string', 'max:500'],
        ]);

        $agent = User::query()->where('branch_id', $branchId)->find($data['agent_id']);

        if (!$agent) {
            return response()->json(['message' => 'Agent not found in this branch.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $payout = $this->referral()->payAdvance(
                agent: $agent,
                amount: (float) $data['amount'],
                method: $data['method'],
                destination: [
                    'upi_id' => $data['upi_id'] ?? null,
                    'account_number' => $data['account_number'] ?? null,
                    'ifsc_code' => $data['ifsc_code'] ?? null,
                    'account_name' => $data['account_name'] ?? null,
                ],
                adminId: $request->user()->id,
                notes: $data['notes'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ReferralPresenter::payout($payout)]);
    }

    public function processPayout(Request $request, CommissionPayout $payout): JsonResponse
    {
        $branchId = $this->branchIdFor($request);

        if ((int) $payout->branch_id !== $branchId) {
            return response()->json(['message' => 'Payout not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'status' => ['required', 'string', 'in:' . CommissionPayout::STATUS_APPROVED . ',' . CommissionPayout::STATUS_REJECTED],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        try {
            $payout = $this->referral()->processPayout(
                $payout,
                $data['status'],
                $request->user()->id,
                $data['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ReferralPresenter::payout($payout)]);
    }

    // ---- Audit -----------------------------------------------------------

    public function audits(Request $request): JsonResponse
    {
        $branchId = $this->branchIdFor($request);

        $query = ReferralAudit::query()
            ->where('branch_id', $branchId)
            ->with(['user:id,name', 'actor:id,name']);

        if (filled($request->query('user_id'))) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if (filled($request->query('action'))) {
            $query->where('action', $request->query('action'));
        }

        // Newest first, stated on both columns so the order does not depend on
        // ids and timestamps agreeing.
        $paginator = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(100, max(5, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($audit) => ReferralPresenter::audit($audit))
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * CSV of the ledger for the accountant. Streamed so a long date range does
     * not build the whole file in memory.
     */
    public function export(Request $request)
    {
        $branchId = $this->branchIdFor($request);

        $query = CommissionEntry::query()
            ->where('branch_id', $branchId)
            ->with('agent:id,name,play_id,unique_number')
            ->orderBy('id');

        if (filled($request->query('from'))) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }

        if (filled($request->query('to'))) {
            $query->whereDate('created_at', '<=', $request->query('to'));
        }

        $filename = 'commission-ledger-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Entry ID', 'Date', 'Agent', 'Agent ID', 'Type', 'Status', 'Level',
                'From User', 'Deposit ID', 'Deposit Amount', 'Percent', 'Tier',
                'Commission', 'Deposit Status Now', 'Flag', 'Note',
            ]);

            $query->chunk(500, function ($entries) use ($out) {
                foreach ($entries as $entry) {
                    fputcsv($out, [
                        $entry->id,
                        optional($entry->created_at)->format('Y-m-d H:i'),
                        optional($entry->agent)->name,
                        $entry->agent_id,
                        $entry->type,
                        $entry->status,
                        $entry->level,
                        $entry->from_display_name,
                        $entry->deposit_id,
                        $entry->base_amount,
                        $entry->percent,
                        $entry->tier_label,
                        $entry->amount,
                        $entry->deposit_status_now,
                        $entry->flagged_at ? $entry->flag_reason : '',
                        $entry->note,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ---- Helpers ---------------------------------------------------------

    protected function assertSameBranch(User $user, int $branchId): void
    {
        if ((int) $user->branch_id !== $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'User not found in this branch.');
        }
    }
}
