<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BannerRequest;
use App\Http\Resources\Admin\BannerResource;
use App\Models\Banner;
use App\Support\Cache\BannerCache;
use App\Support\ResolvesBranch;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;


class BannerController extends Controller
{
    use ResolvesBranch;

    public function index()
    {
        $branchId = $this->resolveBranchId(request()->user());

        $banners = BannerCache::rememberAdmin($branchId, function () use ($branchId) {
            return Banner::query()
                ->where('branch_id', $branchId)
                ->orderByDesc('is_logo')
                ->orderBy('sort_order')
                ->orderByDesc('id')
                ->get();
        });

        return BannerResource::collection($banners);
    }

    public function store(BannerRequest $request)
    {
        $validated = $request->validated();
        $branchId = $this->resolveBranchId($request->user());
        $nextIndex = Banner::query()->where('branch_id', $branchId)->count() + 1;

        // Handle multiple uploads in one request when `images` is present
        if ($request->hasFile('images')) {
            $created = collect($request->file('images'))
                ->map(function ($file) use ($request, $validated, $branchId, &$nextIndex) {
                    $storedPath = $this->storeBannerImage($file);
                    $title = blank($validated['title'] ?? null)
                        ? 'Banner ' . $nextIndex++
                        : $validated['title'];

                    return Banner::create([
                        'title' => $title,
                        'is_logo' => $validated['is_logo'] ?? false,
                        'is_active' => $validated['is_active'] ?? true,
                        'sort_order' => $validated['sort_order'] ?? 0,
                        'image_path' => $storedPath,
                        'created_by' => $request->user()->id,
                        'branch_id' => $branchId,
                    ]);
                });

            BannerCache::flushBranch($branchId);

            return response()->json(BannerResource::collection($created), Response::HTTP_CREATED);
        }

        // Single upload with an uploaded file or existing path
        $imagePath = $request->hasFile('image')
            ? $this->storeBannerImage($request->file('image'))
            : ($validated['image_path'] ?? null);

        $title = blank($validated['title'] ?? null)
            ? 'Banner ' . $nextIndex
            : $validated['title'];

        $banner = Banner::create([
            'title' => $title,
            'image_path' => $imagePath,
            'is_logo' => $validated['is_logo'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
            'created_by' => $request->user()->id,
            'branch_id' => $branchId,
        ]);

        BannerCache::flushBranch($branchId);

        return response()->json(new BannerResource($banner), Response::HTTP_CREATED);
    }

    public function show(Banner $banner)
    {
        $this->ensureOwnership($banner, request()->user());

        return new BannerResource($banner);
    }

    public function update(BannerRequest $request, Banner $banner)
    {
        $this->ensureOwnership($banner, $request->user());

        $validated = $request->validated();

        if ($request->hasFile('image')) {
            $this->deleteBannerImage($banner->image_path);
            $validated['image_path'] = $this->storeBannerImage($request->file('image'));
        }

        $banner->update($validated);

        BannerCache::flushBranch($this->resolveBranchId($request->user()));

        return new BannerResource($banner);
    }

    public function destroy(Banner $banner): Response
    {
        $this->ensureOwnership($banner, request()->user());

        $banner->delete();

        BannerCache::flushBranch($this->resolveBranchId(request()->user()));

        return response()->noContent();
    }

    protected function ensureOwnership(Banner $banner, $actor): void
    {
        $branchId = $this->resolveBranchId($actor);

        if ((int) $banner->branch_id !== (int) $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'Banner not found.');
        }
    }

    protected function storeBannerImage($file): string
    {
        $storedPath = $file->store('banners', 'public');

        return 'storage/' . ltrim($storedPath, '/');
    }

    protected function deleteBannerImage(?string $storedPath): void
    {
        if (!$storedPath) {
            return;
        }

        $path = parse_url($storedPath, PHP_URL_PATH) ?: $storedPath;
        $relativePath = preg_replace('#^/?storage/#', '', (string) $path);
        $relativePath = ltrim((string) $relativePath, '/');

        if ($relativePath && Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
        }
    }
}
