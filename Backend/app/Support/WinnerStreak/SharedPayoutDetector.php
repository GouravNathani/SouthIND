<?php

namespace App\Support\WinnerStreak;

use App\Models\Withdrawal;
use Illuminate\Support\Collection;

/**
 * Flags winners whose withdrawal money lands in the same place as another
 * account's.
 *
 * Multi-accounting is the obvious way to farm a per-cycle reward: register five
 * users, push a small win through each, collect five prizes. The tell is almost
 * always the payout destination — the farmer still wants the money in ONE UPI
 * id or bank account.
 *
 * BC already stores that on every withdrawal (`upi_id`, `account_number`), so
 * no new tracking is needed. This is a WARNING for the admin, never an
 * automatic rejection: families and shops legitimately share an account, and
 * silently voiding a real winner's prize is worse than showing a flag.
 */
class SharedPayoutDetector
{
    /**
     * For each given user, how many OTHER users in the branch withdraw to a
     * destination they also use.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array{shared_with: int, destinations: array<int, string>}>
     */
    public static function forUsers(int $branchId, array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }

        // Destinations these users withdraw to.
        $theirs = Withdrawal::query()
            ->where('branch_id', $branchId)
            ->whereIn('user_id', $userIds)
            ->get(['user_id', 'upi_id', 'account_number']);

        $keysByUser = [];
        $allKeys = [];

        foreach ($theirs as $row) {
            $key = self::destinationKey($row->upi_id, $row->account_number);
            if ($key === null) {
                continue;
            }
            $keysByUser[(int) $row->user_id][$key] = true;
            $allKeys[$key] = true;
        }

        if ($allKeys === []) {
            return [];
        }

        // Everyone in the branch using any of those destinations.
        $owners = Withdrawal::query()
            ->where('branch_id', $branchId)
            ->where(function ($query) use ($allKeys) {
                foreach (array_keys($allKeys) as $key) {
                    [$type, $value] = explode(':', $key, 2);
                    $column = $type === 'upi' ? 'upi_id' : 'account_number';
                    $query->orWhere($column, $value);
                }
            })
            ->get(['user_id', 'upi_id', 'account_number']);

        /** @var array<string, Collection<int, int>> $usersByKey */
        $usersByKey = [];
        foreach ($owners as $row) {
            $key = self::destinationKey($row->upi_id, $row->account_number);
            if ($key === null) {
                continue;
            }
            $usersByKey[$key][(int) $row->user_id] = true;
        }

        $result = [];

        foreach ($keysByUser as $userId => $keys) {
            $others = [];
            $destinations = [];

            foreach (array_keys($keys) as $key) {
                $sharers = array_keys($usersByKey[$key] ?? []);
                $sharers = array_filter($sharers, fn ($id) => $id !== $userId);

                if ($sharers !== []) {
                    $others = array_merge($others, $sharers);
                    $destinations[] = self::maskDestination($key);
                }
            }

            if ($others === []) {
                continue;
            }

            $result[$userId] = [
                'shared_with' => count(array_unique($others)),
                'destinations' => array_values(array_unique($destinations)),
            ];
        }

        return $result;
    }

    protected static function destinationKey(?string $upi, ?string $account): ?string
    {
        $upi = trim((string) $upi);
        if ($upi !== '') {
            return 'upi:' . mb_strtolower($upi);
        }

        $account = trim((string) $account);
        if ($account !== '') {
            return 'acc:' . $account;
        }

        return null;
    }

    /**
     * Enough for the admin to recognise the destination, not enough to be a
     * fresh copy of someone's full bank details in an unrelated screen.
     */
    protected static function maskDestination(string $key): string
    {
        [$type, $value] = explode(':', $key, 2);

        if ($type === 'upi') {
            $at = mb_strpos($value, '@');
            if ($at === false || $at <= 2) {
                return $value;
            }

            return mb_substr($value, 0, 2) . str_repeat('*', max(2, $at - 2)) . mb_substr($value, $at);
        }

        $length = mb_strlen($value);

        return $length <= 4 ? $value : str_repeat('*', $length - 4) . mb_substr($value, -4);
    }
}
