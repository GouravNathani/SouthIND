<?php

namespace App\Support\Ticker;

use App\Models\BranchTicker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The home-page strip per branch. Users read it on every home visit, so the
 * public view is cached for a minute and dropped whenever an admin saves.
 */
class TickerService
{
    public const MAX_LENGTH = 300;

    private const CACHE_TTL = 60;

    public static function forBranch(int $branchId): BranchTicker
    {
        return BranchTicker::query()->firstOrNew(['branch_id' => $branchId], ['enabled' => false, 'text' => '']);
    }

    /** What the user app shows: null when it is off or empty. */
    public static function publicView(?int $branchId): ?array
    {
        if (! $branchId) {
            return null;
        }

        return Cache::remember(self::cacheKey($branchId), self::CACHE_TTL, function () use ($branchId) {
            $ticker = BranchTicker::query()->where('branch_id', $branchId)->first();

            return $ticker && $ticker->isShown() ? ['text' => trim((string) $ticker->text)] : null;
        });
    }

    public static function validated(Request $request): array
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'text' => ['nullable', 'string', 'max:'.self::MAX_LENGTH],
        ]);

        $text = trim(preg_replace('/\s+/u', ' ', (string) ($data['text'] ?? '')));
        if ($data['enabled'] && $text === '') {
            abort(response()->json([
                'message' => 'Write the text to show, or switch the strip off.',
                'errors' => ['text' => ['Write the text to show, or switch the strip off.']],
            ], 422));
        }

        return ['enabled' => (bool) $data['enabled'], 'text' => $text];
    }

    public static function save(int $branchId, array $data, ?string $by): BranchTicker
    {
        $ticker = BranchTicker::query()->updateOrCreate(
            ['branch_id' => $branchId],
            $data + ['updated_by' => $by ? mb_substr($by, 0, 120) : null],
        );
        Cache::forget(self::cacheKey($branchId));

        return $ticker;
    }

    public static function serialize(BranchTicker $ticker): array
    {
        return [
            'branch_id' => (int) $ticker->branch_id,
            'enabled' => (bool) $ticker->enabled,
            'text' => (string) $ticker->text,
            'updated_by' => $ticker->updated_by,
            'updated_at' => $ticker->updated_at?->toIso8601String(),
            'max_length' => self::MAX_LENGTH,
        ];
    }

    private static function cacheKey(int $branchId): string
    {
        return "branch-ticker:{$branchId}";
    }
}
