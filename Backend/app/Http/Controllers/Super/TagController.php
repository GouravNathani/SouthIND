<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class TagController extends Controller
{
    /**
     * Super admin oversees every branch. Tags stay branch-scoped, so callers
     * pass a branch_id (usually the branch of the user they are viewing).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Tag::query()->withCount('users')->orderBy('name');

        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', (int) $branchId);
        }

        $tags = $query->get()->map(fn (Tag $tag) => $this->serialize($tag));

        return response()->json(['data' => $tags]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('tags', 'name')->where(fn ($q) => $q->where('branch_id', $request->integer('branch_id'))),
            ],
            'color' => ['required', 'string', 'max:32', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
        ]);

        $tag = Tag::create($data);

        return response()->json(['data' => $this->serialize($tag)], Response::HTTP_CREATED);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('tags', 'name')
                    ->where(fn ($q) => $q->where('branch_id', $tag->branch_id))
                    ->ignore($tag->id),
            ],
            'color' => ['required', 'string', 'max:32', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
        ]);

        $tag->update($data);

        return response()->json(['data' => $this->serialize($tag->refresh())]);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $tag->delete();

        return response()->json(['data' => ['id' => $tag->id]]);
    }

    /**
     * Replace a user's tag set. Only tags in that user's own branch apply.
     */
    public function syncUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        $tagIds = Tag::query()
            ->where('branch_id', $user->branch_id)
            ->whereIn('id', $data['tag_ids'])
            ->pluck('id')
            ->all();

        $user->tags()->sync($tagIds);

        $tags = $user->tags()->orderBy('name')->get()->map(fn (Tag $tag) => $this->serialize($tag));

        return response()->json(['data' => $tags]);
    }

    protected function serialize(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color,
            'branch_id' => $tag->branch_id,
            'users_count' => (int) ($tag->users_count ?? 0),
        ];
    }
}
