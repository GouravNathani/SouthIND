<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\User;
use App\Support\ResolvesBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class TagController extends Controller
{
    use ResolvesBranch;

    public function index(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        $tags = Tag::query()
            ->where('branch_id', $branchId)
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Tag $tag) => $this->serialize($tag));

        return response()->json(['data' => $tags]);
    }

    public function store(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        $data = $this->validatePayload($request, $branchId, null);

        $tag = Tag::create([
            'branch_id' => $branchId,
            'name' => $data['name'],
            'color' => $data['color'],
        ]);

        return response()->json(['data' => $this->serialize($tag)], Response::HTTP_CREATED);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());
        $this->ensureOwnership($tag, $branchId);

        $data = $this->validatePayload($request, $branchId, $tag->id);

        $tag->update([
            'name' => $data['name'],
            'color' => $data['color'],
        ]);

        return response()->json(['data' => $this->serialize($tag->refresh())]);
    }

    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());
        $this->ensureOwnership($tag, $branchId);

        $tag->delete();

        return response()->json(['data' => ['id' => $tag->id]]);
    }

    /**
     * Replace a user's tag set (within the acting branch).
     */
    public function syncUser(Request $request, User $user): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        if ((int) $user->branch_id !== (int) $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'User not found.');
        }

        $data = $request->validate([
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        // Only sync tags that actually belong to this branch.
        $tagIds = Tag::query()
            ->where('branch_id', $branchId)
            ->whereIn('id', $data['tag_ids'])
            ->pluck('id')
            ->all();

        $user->tags()->sync($tagIds);

        $tags = $user->tags()->orderBy('name')->get()->map(fn (Tag $tag) => $this->serialize($tag));

        return response()->json(['data' => $tags]);
    }

    protected function validatePayload(Request $request, int $branchId, ?int $ignoreId): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('tags', 'name')
                    ->where(fn ($q) => $q->where('branch_id', $branchId))
                    ->ignore($ignoreId),
            ],
            'color' => ['required', 'string', 'max:32', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
        ]);
    }

    protected function ensureOwnership(Tag $tag, int $branchId): void
    {
        if ((int) $tag->branch_id !== $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'Tag not found.');
        }
    }

    protected function serialize(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color,
            'users_count' => (int) ($tag->users_count ?? 0),
        ];
    }
}
