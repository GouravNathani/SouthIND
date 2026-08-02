<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BonusCodeRequest;
use App\Http\Requests\Admin\BonusRedemptionUpdateRequest;
use App\Http\Resources\BonusCodeResource;
use App\Http\Resources\BonusRedemptionResource;
use App\Models\BonusCode;
use App\Models\BonusCodeRedemption;
use App\Models\Tag;
use App\Support\Bonus\BonusCodeGenerator;
use App\Support\Push\UserPushNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super admin oversees every branch and can additionally create "global"
 * codes (branch_id = null) that work in all of them.
 */
class BonusCodeController extends Controller
{
    // ---- Codes ------------------------------------------------------------

    public function index(Request $request)
    {
        $query = BonusCode::query()->with(['tags', 'creator'])->latest();

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->query('branch_id');
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        if ($request->boolean('global_only')) {
            $query->whereNull('branch_id');
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', '%' . BonusCode::normalise($search) . '%')
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
        $data = $this->attributes($request);

        $bonusCode = new BonusCode($data);
        // Omitted / empty branch_id means the code works in every branch.
        $bonusCode->branch_id = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        $bonusCode->created_by = $request->user()->id;
        $bonusCode->created_by_role = 'super';
        $bonusCode->save();

        $this->syncTags($request, $bonusCode);

        return (new BonusCodeResource($bonusCode->load(['tags', 'creator'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(BonusCode $bonusCode)
    {
        return new BonusCodeResource($bonusCode->load(['tags', 'creator']));
    }

    public function update(BonusCodeRequest $request, BonusCode $bonusCode)
    {
        $bonusCode->fill($this->attributes($request));

        if ($request->has('branch_id')) {
            $bonusCode->branch_id = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        }

        $bonusCode->save();

        $this->syncTags($request, $bonusCode);

        return new BonusCodeResource($bonusCode->load(['tags', 'creator']));
    }

    public function destroy(BonusCode $bonusCode)
    {
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
        $query = BonusCodeRedemption::query()->with(['user', 'fulfiller'])->latest();

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->query('branch_id'));
        }

        if ($codeId = $request->query('bonus_code_id')) {
            $query->where('bonus_code_id', (int) $codeId);
        }

        $status = $request->query('status');
        if ($status && in_array($status, BonusCodeRedemption::STATUSES, true)) {
            $query->where('status', $status);
        } else {
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

    public function userHistory(int $user)
    {
        $redemptions = BonusCodeRedemption::query()
            ->where('user_id', $user)
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

    protected function syncTags(BonusCodeRequest $request, BonusCode $bonusCode): void
    {
        if (!$request->has('tag_ids')) {
            return;
        }

        $tagIds = collect($request->input('tag_ids', []))->map(fn ($id) => (int) $id);

        // A branch-scoped code may only target that branch's tags.
        if ($bonusCode->branch_id) {
            $tagIds = Tag::query()
                ->whereIn('id', $tagIds)
                ->where('branch_id', $bonusCode->branch_id)
                ->pluck('id');
        }

        $bonusCode->tags()->sync($tagIds->all());
    }
}
