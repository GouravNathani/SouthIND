<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Concerns\ProxiesWaHub;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\WaHub\WaHubClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * WhatsApp (WA Hub) overview for the super admin: every branch's number, the
 * per-branch on/off switch, and history / campaigns / usage across branches.
 * Rows carry `branch_id` + `branch_name` (from the `branch-{id}` key).
 */
class WaHubController extends Controller
{
    use ProxiesWaHub;

    /** Message list filters passed through to the hub. */
    private const MESSAGE_FILTERS = ['kind', 'event', 'status', 'campaign_id', 'ref_user_id', 'q', 'from', 'to', 'page', 'per_page'];

    public function __construct(private readonly WaHubClient $hub)
    {
    }

    /** One row per branch; a branch the hub has never seen shows `not_linked`. */
    public function connections(): JsonResponse
    {
        $branches = Branch::query()->orderBy('id')->get(['id', 'name']);

        return $this->proxy(fn () => $this->hub->connections(), function (array $data) use ($branches): array {
            $byKey = collect($data['connections'] ?? [])
                ->filter(fn ($connection) => is_array($connection) && isset($connection['key']))
                ->keyBy('key');

            return [
                'connections' => $branches->map(function (Branch $branch) use ($byKey): array {
                    $key = WaHubClient::connectionKey($branch->id);

                    return [
                        ...($byKey->get($key) ?? self::notLinked($key, $branch->name)),
                        'branch_id' => $branch->id,
                        'branch_name' => $branch->name,
                    ];
                })->values()->all(),
            ];
        });
    }

    public function enable(int $branch): JsonResponse
    {
        return $this->setEnabled($branch, true);
    }

    public function disable(int $branch): JsonResponse
    {
        return $this->setEnabled($branch, false);
    }

    public function messages(Request $request): JsonResponse
    {
        $request->validate(['branch_id' => ['nullable', 'integer']]);
        $branches = $this->branchNames();

        return $this->proxy(
            fn () => $this->hub->messages([...$this->hubFilters($request, self::MESSAGE_FILTERS), ...$this->branchFilter($request)]),
            fn (array $data): array => $this->withBranch($data, 'data', 'connection', $branches),
        );
    }

    public function campaigns(Request $request): JsonResponse
    {
        $request->validate(['branch_id' => ['nullable', 'integer']]);
        $branches = $this->branchNames();

        return $this->proxy(
            fn () => $this->hub->campaigns([...$this->hubFilters($request, ['page', 'per_page']), ...$this->branchFilter($request)]),
            fn (array $data): array => $this->withBranch($data, 'data', 'connection', $branches),
        );
    }

    public function usage(): JsonResponse
    {
        $branches = $this->branchNames();

        return $this->proxy(
            fn () => $this->hub->usage(),
            fn (array $data): array => $this->withBranch($data, 'by_connection', 'connection', $branches),
        );
    }

    // ---- Helpers ----------------------------------------------------------

    private function setEnabled(int $branchId, bool $enabled): JsonResponse
    {
        $branch = Branch::query()->findOrFail($branchId);

        return $this->proxy(
            fn () => $this->hub->saveConnection(WaHubClient::connectionKey($branch->id), [
                'label' => $branch->name,
                'enabled' => $enabled,
            ]),
            fn (array $data): array => [...$data, 'branch_id' => $branch->id, 'branch_name' => $branch->name],
        );
    }

    /** @return array<string, string> */
    private function branchFilter(Request $request): array
    {
        $branchId = (int) $request->query('branch_id');

        return $branchId > 0 ? ['connection' => WaHubClient::connectionKey($branchId)] : [];
    }

    /** @return Collection<int, string> branch id → name */
    private function branchNames(): Collection
    {
        return Branch::query()->pluck('name', 'id');
    }

    /**
     * Add branch_id / branch_name to each row of `$data[$listKey]`.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, string>  $branches
     * @return array<string, mixed>
     */
    private function withBranch(array $data, string $listKey, string $keyField, Collection $branches): array
    {
        if (!is_array($data[$listKey] ?? null)) {
            return $data;
        }

        $data[$listKey] = array_map(function ($row) use ($keyField, $branches) {
            if (!is_array($row)) {
                return $row;
            }

            $branchId = WaHubClient::branchIdFromKey($row[$keyField] ?? null);

            return [
                ...$row,
                'branch_id' => $branchId,
                'branch_name' => $branchId !== null ? $branches->get($branchId) : null,
            ];
        }, $data[$listKey]);

        return $data;
    }

    /**
     * The Connection shape for a branch the hub has not created yet.
     *
     * @return array<string, mixed>
     */
    private static function notLinked(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => 'not_linked',
            'socket' => null,
            'me' => null,
            'qr' => null,
            'qr_expires_in' => 0,
            'qr_attempt' => 0,
            'enabled' => true,
            'header' => '',
            'footer' => '',
            'today' => ['sent' => 0, 'cap' => null],
            'linked_at' => null,
            'last_sent_at' => null,
        ];
    }
}
