<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BonusCodeRequest;
use App\Http\Requests\Admin\BonusRedemptionUpdateRequest;
use App\Http\Resources\BonusCodeResource;
use App\Http\Resources\BonusRedemptionResource;
use App\Models\BonusCode;
use App\Models\BonusCodeRedemption;
use App\Support\Bonus\BonusCodeGenerator;
use App\Support\Push\UserPushNotifier;
use App\Support\ResolvesBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class BonusCodeController extends Controller
{
    use ResolvesBranch;

    // ---- Codes ------------------------------------------------------------

    public function index(Request $request)
    {
        $branchId = $this->resolveBranchId($request->user());

        $query = BonusCode::query()
            ->where('branch_id', $branchId)
            ->with(['tags', 'creator'])
            ->latest();

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $pattern = '%' . BonusCode::normalise($search) . '%';
            $query->where(function ($q) use ($pattern, $search) {
                $q->where('code', 'like', $pattern)
                    ->orWhere('title', 'like', '%' . $search . '%');
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return BonusCodeResource::collection($query->get());
    }

    public function store(BonusCodeRequest $request)
    {
        $branchId = $this->resolveBranchId($request->user());

        $bonusCode = new BonusCode($this->attributes($request));
        $bonusCode->branch_id = $branchId;
        $bonusCode->created_by = $request->user()->id;
        $bonusCode->created_by_role = 'admin';
        $bonusCode->save();

        $this->syncTags($request, $bonusCode, $branchId);

        return (new BonusCodeResource($bonusCode->load(['tags', 'creator'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, BonusCode $bonusCode)
    {
        $this->ensureOwnership($bonusCode, $request->user());

        return new BonusCodeResource($bonusCode->load(['tags', 'creator']));
    }

    public function update(BonusCodeRequest $request, BonusCode $bonusCode)
    {
        $branchId = $this->ensureOwnership($bonusCode, $request->user());

        $bonusCode->fill($this->attributes($request));
        $bonusCode->save();

        $this->syncTags($request, $bonusCode, $branchId);

        return new BonusCodeResource($bonusCode->load(['tags', 'creator']));
    }

    /**
     * Codes are never hard-deleted once used — the redemption history (and the
     * fraud trail attached to it) has to survive.
     */
    public function destroy(Request $request, BonusCode $bonusCode)
    {
        $this->ensureOwnership($bonusCode, $request->user());

        if ($bonusCode->redemptions()->exists()) {
            $bonusCode->status = BonusCode::STATUS_PAUSED;
            $bonusCode->save();

            return response()->json([
                'message' => 'Code has redemptions, so it was paused instead of deleted.',
                'data' => new BonusCodeResource($bonusCode->load(['tags', 'creator'])),
            ]);
        }

        $bonusCode->delete();

        return response()->json(['message' => 'Bonus code deleted.']);
    }

    /**
     * Suggest an unused random code for the create form.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'length' => ['sometimes', 'integer', 'min:4', 'max:20'],
            'prefix' => ['sometimes', 'nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9]*$/'],
            'count' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $codes = BonusCodeGenerator::batch(
            (int) ($data['count'] ?? 1),
            (int) ($data['length'] ?? 8),
            (string) ($data['prefix'] ?? ''),
        );

        return response()->json(['data' => $codes]);
    }

    // ---- Redemptions ------------------------------------------------------

    public function redemptions(Request $request)
    {
        $branchId = $this->resolveBranchId($request->user());

        $query = BonusCodeRedemption::query()
            ->where('branch_id', $branchId)
            ->with(['user', 'fulfiller'])
            ->latest();

        if ($codeId = $request->query('bonus_code_id')) {
            $query->where('bonus_code_id', (int) $codeId);
        }

        $status = $request->query('status');
        if ($status && in_array($status, BonusCodeRedemption::STATUSES, true)) {
            $query->where('status', $status);
        } else {
            // Default view: everything that needs (or had) an admin decision.
            $query->whereIn('status', [
                BonusCodeRedemption::STATUS_PENDING,
                BonusCodeRedemption::STATUS_FULFILLED,
                BonusCodeRedemption::STATUS_REJECTED,
            ]);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            $query->where(function ($q) use ($pattern) {
                $q->where('code', 'like', $pattern)
                    ->orWhereHas('user', function ($q) use ($pattern) {
                        $q->where('name', 'like', $pattern)
                            ->orWhere('phone', 'like', $pattern)
                            ->orWhere('unique_number', 'like', $pattern);
                    });
            });
        }

        return BonusRedemptionResource::collection($query->limit(500)->get());
    }

    public function updateRedemption(BonusRedemptionUpdateRequest $request, BonusCodeRedemption $redemption)
    {
        $branchId = $this->resolveBranchId($request->user());

        if ((int) $redemption->branch_id !== (int) $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'Not found.');
        }

        if ($redemption->status === BonusCodeRedemption::STATUS_AWAITING_DEPOSIT) {
            return response()->json(
                ['message' => 'This bonus unlocks once the linked deposit is approved.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $data = $request->validated();

        $redemption->status = $data['status'];
        if (array_key_exists('notes', $data)) {
            $redemption->notes = $data['notes'];
        }
        $redemption->fulfilled_by = $request->user()->id;
        $redemption->fulfilled_at = now();
        $redemption->save();

        if ($data['status'] === BonusCodeRedemption::STATUS_FULFILLED) {
            try {
                UserPushNotifier::notifyBonusApproved(
                    (int) $redemption->user_id,
                    $redemption->amount,
                    $redemption->reward_label,
                );
            } catch (\Throwable $e) {
                Log::warning('Bonus approval push failed.', ['error' => $e->getMessage()]);
            }
        }

        return new BonusRedemptionResource($redemption->load(['user', 'fulfiller']));
    }

    /**
     * Every bonus a single user has taken — shown alongside their deposits so
     * an admin can spot abuse without leaving the details page.
     */
    public function userHistory(Request $request, int $user)
    {
        $branchId = $this->resolveBranchId($request->user());

        $redemptions = BonusCodeRedemption::query()
            ->where('user_id', $user)
            ->where('branch_id', $branchId)
            ->with(['user', 'fulfiller'])
            ->latest()
            ->limit(100)
            ->get();

        return BonusRedemptionResource::collection($redemptions);
    }

    // ---- Helpers ----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function attributes(BonusCodeRequest $request): array
    {
        $data = $request->validated();
        unset($data['tag_ids']);

        $data['per_user_limit'] = $data['per_user_limit'] ?? 1;
        $data['min_deposit'] = $data['min_deposit'] ?? 0;
        $data['status'] = $data['status'] ?? BonusCode::STATUS_ACTIVE;
        $data['auto_approve'] = $request->boolean('auto_approve');
        $data['requires_deposit'] = $request->boolean('requires_deposit');

        return $data;
    }

    protected function syncTags(BonusCodeRequest $request, BonusCode $bonusCode, ?int $branchId): void
    {
        if (!$request->has('tag_ids')) {
            return;
        }

        $tagIds = collect($request->input('tag_ids', []))->map(fn ($id) => (int) $id);

        // Tags are branch-scoped; never let one branch target another's tags.
        if ($branchId) {
            $tagIds = \App\Models\Tag::query()
                ->whereIn('id', $tagIds)
                ->where('branch_id', $branchId)
                ->pluck('id');
        }

        $bonusCode->tags()->sync($tagIds->all());
    }

    protected function ensureOwnership(BonusCode $bonusCode, $actor): int
    {
        $branchId = $this->resolveBranchId($actor);

        if ((int) $bonusCode->branch_id !== (int) $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'Not found.');
        }

        return $branchId;
    }
}
