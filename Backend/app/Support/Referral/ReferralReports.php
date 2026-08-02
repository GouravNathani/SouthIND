<?php

namespace App\Support\Referral;

use App\Models\CommissionAccount;
use App\Models\CommissionEntry;
use App\Models\CommissionPayout;
use App\Models\Deposit;
use App\Models\ReferralSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Read-side of the referral module: the numbers the panels display.
 *
 * Kept apart from ReferralService so the mutating surface stays small and
 * obvious. Everything here takes a branch id and never crosses it.
 */
class ReferralReports
{
    /** Headline tiles for the branch's referral page. */
    public static function overview(int $branchId): array
    {
        $settings = ReferralSetting::forBranch($branchId);

        $accounts = CommissionAccount::query()
            ->where('branch_id', $branchId)
            ->selectRaw('
                COUNT(*) AS agents,
                COALESCE(SUM(available_balance), 0) AS available,
                COALESCE(SUM(pending_balance), 0) AS pending,
                COALESCE(SUM(lifetime_earned), 0) AS earned,
                COALESCE(SUM(lifetime_paid), 0) AS paid,
                COALESCE(SUM(team_count), 0) AS team_total
            ')
            ->first();

        $activeAgents = User::query()
            ->where('branch_id', $branchId)
            ->where('user_type', User::TYPE_AGENT)
            ->where('agent_status', User::AGENT_ACTIVE)
            ->count();

        $flagged = CommissionEntry::query()
            ->where('branch_id', $branchId)
            ->needsReview()
            ->count();

        $pendingPayouts = CommissionPayout::query()
            ->where('branch_id', $branchId)
            ->where('status', CommissionPayout::STATUS_PENDING)
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount), 0) AS total')
            ->first();

        $thisMonth = (float) CommissionEntry::query()
            ->where('branch_id', $branchId)
            ->whereIn('type', [CommissionEntry::TYPE_ACCRUAL, CommissionEntry::TYPE_BONUS])
            ->where('status', '<>', CommissionEntry::STATUS_VOID)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('amount');

        return [
            'enabled' => (bool) $settings->enabled,
            'agents' => (int) ($accounts->agents ?? 0),
            'active_agents' => $activeAgents,
            'referred_users' => (int) ($accounts->team_total ?? 0),
            // What the branch owes agents right now — the liability tile.
            'liability' => round((float) ($accounts->available ?? 0) + (float) ($accounts->pending ?? 0), 2),
            'available_total' => round((float) ($accounts->available ?? 0), 2),
            'pending_total' => round((float) ($accounts->pending ?? 0), 2),
            'lifetime_earned' => round((float) ($accounts->earned ?? 0), 2),
            'lifetime_paid' => round((float) ($accounts->paid ?? 0), 2),
            'earned_this_month' => round($thisMonth, 2),
            'flagged_entries' => $flagged,
            'pending_payouts' => (int) ($pendingPayouts->c ?? 0),
            'pending_payout_total' => round((float) ($pendingPayouts->total ?? 0), 2),
        ];
    }

    /**
     * The branch's agents, newest-earning first by default.
     */
    public static function agents(int $branchId, array $filters = [], int $perPage = 25)
    {
        $query = User::query()
            ->where('users.branch_id', $branchId)
            ->where('users.user_type', User::TYPE_AGENT)
            ->leftJoin('commission_accounts', 'commission_accounts.user_id', '=', 'users.id')
            ->select('users.*')
            ->with('referrer:id,name');

        if (filled($filters['search'] ?? null)) {
            $pattern = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($filters['search'])) . '%';
            $query->where(function ($q) use ($pattern) {
                $q->where('users.name', 'like', $pattern)
                    ->orWhere('users.phone', 'like', $pattern)
                    ->orWhere('users.play_id', 'like', $pattern)
                    ->orWhere('users.unique_number', 'like', $pattern)
                    ->orWhere('users.referral_code', 'like', $pattern);
            });
        }

        if (filled($filters['agent_status'] ?? null)) {
            $query->where('users.agent_status', $filters['agent_status']);
        }

        $sort = $filters['sort'] ?? 'earned';

        match ($sort) {
            'team' => $query->orderByDesc(DB::raw('COALESCE(commission_accounts.team_count, 0)')),
            'balance' => $query->orderByDesc(DB::raw('COALESCE(commission_accounts.available_balance, 0)')),
            'volume' => $query->orderByDesc(DB::raw('COALESCE(commission_accounts.team_deposit_total, 0)')),
            'newest' => $query->orderByDesc('users.created_at'),
            default => $query->orderByDesc(DB::raw('COALESCE(commission_accounts.lifetime_earned, 0)')),
        };

        return $query->paginate($perPage);
    }

    /**
     * An agent's team with each member's approved deposit total and the
     * commission that member has produced.
     *
     * @return array<int, array>
     */
    public static function team(User $agent, bool $forAdmin = false, int $limit = 200): array
    {
        $members = User::query()
            ->where('referred_by', $agent->id)
            ->orderByDesc('referred_at')
            ->limit($limit)
            ->get();

        if ($members->isEmpty()) {
            return [];
        }

        $ids = $members->pluck('id')->all();

        $deposits = Deposit::query()
            ->whereIn('user_id', $ids)
            ->where('status', Deposit::STATUS_APPROVED)
            ->groupBy('user_id')
            ->selectRaw('user_id, COALESCE(SUM(amount), 0) AS total')
            ->pluck('total', 'user_id');

        $earned = CommissionEntry::query()
            ->where('agent_id', $agent->id)
            ->whereIn('from_user_id', $ids)
            ->where('type', CommissionEntry::TYPE_ACCRUAL)
            ->where('status', '<>', CommissionEntry::STATUS_VOID)
            ->groupBy('from_user_id')
            ->selectRaw('from_user_id, COALESCE(SUM(amount), 0) AS total')
            ->pluck('total', 'from_user_id');

        return $members->map(fn (User $member) => $forAdmin
            ? ReferralPresenter::teamMemberForAdmin(
                $member,
                (float) ($deposits[$member->id] ?? 0),
                (float) ($earned[$member->id] ?? 0),
            )
            : ReferralPresenter::teamMemberForUser(
                $member,
                (float) ($deposits[$member->id] ?? 0),
                (float) ($earned[$member->id] ?? 0),
            ))->all();
    }

    /**
     * Top agents for the branch leaderboard.
     */
    public static function leaderboard(int $branchId, int $limit = 10): array
    {
        return CommissionAccount::query()
            ->where('branch_id', $branchId)
            ->with('user:id,name,play_id,unique_number,referral_code')
            ->orderByDesc('lifetime_earned')
            ->limit($limit)
            ->get()
            ->map(fn (CommissionAccount $account) => [
                'agent_id' => (int) $account->user_id,
                'name' => optional($account->user)->name,
                'play_id' => optional($account->user)->play_id ?: optional($account->user)->unique_number,
                'team_count' => (int) $account->team_count,
                'team_deposit_total' => (float) $account->team_deposit_total,
                'lifetime_earned' => (float) $account->lifetime_earned,
                'tier_label' => $account->tier_label,
            ])
            ->all();
    }

    /**
     * Branch-wise roll-up for the SuperAdmin landing view.
     */
    public static function branchSummaries(): array
    {
        $rows = CommissionAccount::query()
            ->groupBy('branch_id')
            ->selectRaw('
                branch_id,
                COUNT(*) AS agents,
                COALESCE(SUM(available_balance + pending_balance), 0) AS liability,
                COALESCE(SUM(lifetime_earned), 0) AS earned,
                COALESCE(SUM(lifetime_paid), 0) AS paid,
                COALESCE(SUM(team_count), 0) AS referred
            ')
            ->get();

        $enabled = ReferralSetting::query()->pluck('enabled', 'branch_id');
        $branches = \App\Models\Branch::query()->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'branch_id' => (int) $row->branch_id,
            'branch_name' => $branches[$row->branch_id] ?? null,
            'enabled' => (bool) ($enabled[$row->branch_id] ?? false),
            'agents' => (int) $row->agents,
            'referred_users' => (int) $row->referred,
            'liability' => round((float) $row->liability, 2),
            'lifetime_earned' => round((float) $row->earned, 2),
            'lifetime_paid' => round((float) $row->paid, 2),
        ])->all();
    }

    /**
     * The agent's own dashboard payload for the user app.
     */
    public static function agentDashboard(User $agent): array
    {
        $settings = ReferralSetting::forBranch((int) $agent->branch_id);
        $account = CommissionAccount::forUser($agent);

        CommissionLedger::refreshTeamStats((int) $agent->id);
        $account->refresh();

        $rate = CommissionRate::resolve($agent, $settings);
        $next = $settings->tiers_enabled
            ? CommissionRate::nextTier($agent, $settings, (float) $account->team_deposit_total)
            : null;

        $thisMonth = (float) CommissionEntry::query()
            ->where('agent_id', $agent->id)
            ->whereIn('type', [CommissionEntry::TYPE_ACCRUAL, CommissionEntry::TYPE_BONUS])
            ->where('status', '<>', CommissionEntry::STATUS_VOID)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('amount');

        return [
            'is_agent' => $agent->isAgent(),
            'agent_status' => $agent->agent_status,
            'referral_code' => $agent->referral_code,
            'account' => ReferralPresenter::account($account),
            'earned_this_month' => round($thisMonth, 2),
            'rate' => [
                'percent' => $rate['percent'],
                'label' => $rate['label'],
                'source' => $rate['source'],
            ],
            'next_tier' => $next,
            'programme' => [
                'enabled' => (bool) $settings->enabled,
                'min_payout_amount' => (float) $settings->min_payout_amount,
                'holding_hours' => (int) $settings->holding_hours,
                'payout_to_bank_enabled' => (bool) $settings->payout_to_bank_enabled,
                'payout_to_play_enabled' => (bool) $settings->payout_to_play_enabled,
                'level2_enabled' => (bool) $settings->level2_enabled,
                'level2_percent' => (float) $settings->level2_percent,
                'tiers' => $settings->tiers_enabled ? $settings->tierLadder() : [],
            ],
        ];
    }
}
