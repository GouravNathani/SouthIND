<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Move a deposit/withdrawal out of its pending states exactly once.
 *
 * Approve/reject used to check the status in PHP and then update. Two clicks —
 * or two admins — landing together both passed that check, so the status was
 * written twice and every side effect after it (bonus unlock, referral
 * commission, deposit-limit pause, user push) ran twice. Here the row is re-read
 * under a lock inside a transaction: the second request waits for the first,
 * then sees the row is no longer pending and backs off.
 */
class StatusTransition
{
    /**
     * @param  list<string>  $from  statuses the record may still be moved out of
     * @param  array<string, mixed>  $payload
     * @return bool false when another request already moved it
     */
    public static function claim(Model $model, array $from, array $payload): bool
    {
        $claimed = DB::transaction(function () use ($model, $from, $payload): ?Model {
            $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->first();

            if (!$locked || !in_array($locked->getAttribute('status'), $from, true)) {
                return null;
            }

            $locked->update($payload);

            return $locked;
        });

        if ($claimed === null) {
            return false;
        }

        // Callers keep using their own instance for the side effects that follow.
        $model->setRawAttributes($claimed->getAttributes(), true);

        return true;
    }
}
