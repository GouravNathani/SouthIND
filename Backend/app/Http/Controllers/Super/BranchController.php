<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Http\Requests\Super\StoreBranchRequest;
use App\Http\Requests\Super\UpdateBranchRequest;
use App\Http\Resources\Super\BranchResource;
use App\Models\Admin;
use App\Models\AppSetting;
use App\Models\Banner;
use App\Models\Branch;
use App\Support\Cache\AppSettingCache;
use App\Support\Cache\BannerCache;
use App\Support\Referral\ReferralService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class BranchController extends Controller
{
    public function index()
    {
        $branches = Branch::query()
            ->with(['referralSetting', 'appSetting'])
            ->withCount(['admins', 'users'])
            ->orderBy('created_at', 'desc')
            ->get();

        return BranchResource::collection($branches);
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        $data = $request->validated();
        $copyFrom = $data['copy_from_branch_id'] ?? null;
        $agentEnabled = array_key_exists('agent_enabled', $data) ? (bool) $data['agent_enabled'] : null;
        unset($data['copy_from_branch_id'], $data['agent_enabled']);

        $data['name'] = trim((string) $data['name']);
        $data['code'] = $this->resolveBranchCode($data['code'] ?? null);
        if (array_key_exists('domain', $data)) {
            $domain = trim((string) $data['domain']);
            $data['domain'] = $domain === '' ? null : $domain;
        }

        $branch = Branch::create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        $this->seedBranch($branch, $copyFrom, $request->user());

        if ($agentEnabled !== null) {
            $this->setAgentEnabled($branch, $agentEnabled, $request->user());
        }

        return response()->json(
            new BranchResource($branch->load('referralSetting')->loadCount(['admins', 'users'])),
            Response::HTTP_CREATED
        );
    }

    public function show(Branch $branch)
    {
        return new BranchResource($branch->load('referralSetting')->loadCount(['admins', 'users']));
    }

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $payload = array_filter($request->validated(), static fn ($value) => $value !== null);

        // Agent switch lives in referral_settings, not on the branch row.
        if (array_key_exists('agent_enabled', $payload)) {
            $this->setAgentEnabled($branch, (bool) $payload['agent_enabled'], $request->user());
            unset($payload['agent_enabled']);
        }

        if (array_key_exists('code', $payload)) {
            $payload['code'] = strtoupper(trim((string) $payload['code']));
        }

        if (array_key_exists('name', $payload)) {
            $payload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('domain', $payload)) {
            $domain = trim((string) $payload['domain']);
            $payload['domain'] = $domain === '' ? null : $domain;
        }

        if ($payload !== []) {
            $branch->update($payload);
        }
        AppSettingCache::flushBranch($branch->id);

        return new BranchResource($branch->load('referralSetting')->loadCount(['admins', 'users']));
    }

    public function destroy(Branch $branch): Response
    {
        if ($branch->admins()->exists() || $branch->users()->exists()) {
            return response()->json([
                'message' => 'Branch cannot be deleted while admins or users exist.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $branch->delete();

        return response()->noContent();
    }

    /**
     * Flip the branch's referral / agent programme. Routed through the service
     * so the change lands in the referral audit trail exactly like a flip made
     * from the Agent page itself — this is the same flag, not a second one.
     */
    protected function setAgentEnabled(Branch $branch, bool $enabled, Admin $actor): void
    {
        app(ReferralService::class)->updateSettings(
            (int) $branch->id,
            ['enabled' => $enabled],
            (int) $actor->id,
        );
    }

    protected function seedBranch(Branch $branch, ?int $sourceBranchId, Admin $actor): void
    {
        $sourceBranch = $sourceBranchId
            ? Branch::query()->find($sourceBranchId)
            : Branch::query()->where('id', '!=', $branch->id)->latest('id')->first();

        if (!$sourceBranch) {
            return;
        }

        if ($branch->min_deposit_amount === null && $sourceBranch->min_deposit_amount !== null) {
            $branch->min_deposit_amount = $sourceBranch->min_deposit_amount;
        }
        if ($branch->min_withdrawal_amount === null && $sourceBranch->min_withdrawal_amount !== null) {
            $branch->min_withdrawal_amount = $sourceBranch->min_withdrawal_amount;
        }
        if ($branch->isDirty(['min_deposit_amount', 'min_withdrawal_amount'])) {
            $branch->save();
        }

        $setting = AppSetting::query()->where('branch_id', $sourceBranch->id)->latest('id')->first();
        if ($setting) {
            AppSetting::create([
                'deposit_offer_text' => $setting->deposit_offer_text,
                'withdrawal_offer_text' => $setting->withdrawal_offer_text,
                'whatsapp_number' => $setting->whatsapp_number,
                'deposit_wa' => $setting->deposit_wa,
                'withdrawal_wa' => $setting->withdrawal_wa,
                'whatsapp_link' => $setting->whatsapp_link,
                'logo_path' => $setting->logo_path,
                'owner_admin_id' => $actor->id,
                'created_by' => $actor->id,
                'branch_id' => $branch->id,
            ]);

            AppSettingCache::flushBranch($branch->id);
        }

        $banners = Banner::query()->where('branch_id', $sourceBranch->id)->get();
        if ($banners->isNotEmpty()) {
            foreach ($banners as $banner) {
                Banner::create([
                    'title' => $banner->title,
                    'image_path' => $banner->image_path,
                    'is_logo' => $banner->is_logo,
                    'is_active' => $banner->is_active,
                    'sort_order' => $banner->sort_order,
                    'created_by' => $actor->id,
                    'branch_id' => $branch->id,
                ]);
            }

            BannerCache::flushBranch($branch->id);
        }
    }

    protected function resolveBranchCode(?string $code): string
    {
        $trimmed = trim((string) $code);
        if ($trimmed !== '') {
            return strtoupper($trimmed);
        }

        $max = 0;
        foreach (Branch::query()->pluck('code') as $existing) {
            if (!is_string($existing)) {
                continue;
            }
            $digits = preg_replace('/\D+/', '', $existing);
            if ($digits === '') {
                continue;
            }
            $value = (int) ltrim($digits, '0');
            if ($value > $max) {
                $max = $value;
            }
        }

        $next = $max + 1;
        while (true) {
            $candidate = str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            if (!Branch::query()->where('code', $candidate)->exists()) {
                return $candidate;
            }
            $next++;
        }
    }
}
