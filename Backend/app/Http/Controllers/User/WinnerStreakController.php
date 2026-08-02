<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\WinnerStreakCycle;
use App\Models\WinnerStreakEntry;
use App\Models\WinnerStreakSetting;
use App\Support\WinnerStreak\WinnerStreakPeriod;
use App\Support\WinnerStreak\WinnerStreakPresenter;
use App\Support\WinnerStreak\WinnerStreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The public face of Winner Streak: recent winners for the caller's own branch.
 *
 * Reads FROZEN entries only — never the deposit/withdrawal tables — so the
 * dashboard ribbon polling this endpoint costs one indexed query, cached. That
 * matters: this is the one Winner Streak endpoint every user hits on every
 * dashboard load.
 *
 * The loss board is withheld unless the branch admin has explicitly published
 * it; by default naming the biggest loser stays inside the admin panel.
 */
class WinnerStreakController extends Controller
{
    protected const TTL = 120;

    public function recent(Request $request): JsonResponse
    {
        $branchId = (int) $request->user()->branch_id;

        if (!$branchId) {
            return response()->json(['data' => ['periods' => [], 'ribbon' => []]]);
        }

        $cycleLimit = max(1, min(10, (int) $request->query('cycles', 3)));

        $payload = Cache::remember(
            WinnerStreakService::userFeedKey($branchId, $cycleLimit),
            self::TTL,
            fn () => $this->build($branchId, $cycleLimit),
        );

        return response()->json(['data' => $payload]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function build(int $branchId, int $cycleLimit): array
    {
        $settings = WinnerStreakSetting::query()
            ->where('branch_id', $branchId)
            ->where('enabled', true)
            ->get()
            ->keyBy('period');

        $periods = [];
        $ribbon = [];

        foreach (WinnerStreakSetting::PERIODS as $period) {
            /** @var WinnerStreakSetting|null $config */
            $config = $settings[$period] ?? null;
            if (!$config) {
                continue;
            }

            $cycles = WinnerStreakCycle::query()
                ->where('branch_id', $branchId)
                ->where('period', $period)
                ->closed()
                ->with(['entries' => fn ($q) => $q->orderBy('rank')])
                ->orderByDesc('starts_at')
                ->limit($cycleLimit)
                ->get();

            $showLoss = $config->loss_board_enabled && $config->loss_board_public;

            $rendered = $cycles->map(function (WinnerStreakCycle $cycle) use ($config, $showLoss) {
                $winners = $cycle->entries
                    ->where('kind', WinnerStreakEntry::KIND_PROFIT)
                    ->map(fn (WinnerStreakEntry $e) => WinnerStreakPresenter::forUser($e, $config))
                    ->values()
                    ->all();

                $losers = $showLoss
                    ? $cycle->entries
                        ->where('kind', WinnerStreakEntry::KIND_LOSS)
                        ->map(fn (WinnerStreakEntry $e) => WinnerStreakPresenter::forUser($e, $config))
                        ->values()
                        ->all()
                    : [];

                return [
                    'label' => $cycle->label,
                    'closed_at' => $cycle->closed_at?->toIso8601String(),
                    'winners' => $winners,
                    'losers' => $losers,
                ];
            })->values()->all();

            $periods[$period] = [
                'title' => WinnerStreakPeriod::periodLabel($period),
                'next_reset_at' => $config->next_reset_at?->toIso8601String(),
                'cycles' => $rendered,
            ];

            // Ribbon: the newest cycle that actually produced winners. Falling
            // back past an empty cycle matters — a quiet day would otherwise
            // blank the ribbon even though yesterday had a winner to show.
            $latest = null;
            foreach ($rendered as $candidate) {
                if (($candidate['winners'] ?? []) !== []) {
                    $latest = $candidate;
                    break;
                }
            }

            foreach ($latest['winners'] ?? [] as $winner) {
                $ribbon[] = [
                    'period' => $period,
                    'title' => WinnerStreakPeriod::periodLabel($period),
                    'rank' => $winner['rank'] ?? null,
                    'name' => $winner['name'] ?? ($winner['play_id'] ?? 'Player'),
                    'play_id' => $winner['play_id'] ?? null,
                    'amount' => $winner['amount'] ?? null,
                ];
            }
        }

        return ['periods' => $periods, 'ribbon' => $ribbon];
    }
}
