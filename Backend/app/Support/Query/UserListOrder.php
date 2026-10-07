<?php

namespace App\Support\Query;

use Illuminate\Database\Eloquent\Builder;

/**
 * Sort orders of the Admin and SuperAdmin user lists, and their reverse.
 *
 * Each sort starts the way people expect (newest, most recently seen, A to Z,
 * biggest profit) and ?reverse=1 flips every part of it, so "oldest", "loss"
 * and Z to A need no options of their own. The list is paginated, so the order
 * belongs here: reversing in the browser would only turn one page around.
 */
final class UserListOrder
{
    /**
     * @param  bool  $hasTotals  the query selects deposit_approved_total / withdrawal_approved_total
     */
    public static function apply(Builder $query, ?string $sortBy, bool $reverse, bool $hasTotals): void
    {
        // Names older SuperAdmin builds still send.
        if ($sortBy === 'oldest' || $sortBy === 'loss') {
            $sortBy = $sortBy === 'oldest' ? 'newest' : 'profit';
            $reverse = !$reverse;
        }
        if ($sortBy === 'profit' && !$hasTotals) {
            $sortBy = 'newest';
        }

        $asc = $reverse ? 'desc' : 'asc';
        $desc = $reverse ? 'asc' : 'desc';

        switch ($sortBy) {
            case 'last_used':
                // Most recently online first; users who never came online sink last.
                $query->orderByRaw("last_seen_at IS NULL {$asc}")->orderBy('last_seen_at', $desc);
                break;
            case 'name':
                // Unnamed users after the named ones.
                $query->orderByRaw("COALESCE(name, '') = '' {$asc}")->orderBy('name', $asc);
                break;
            case 'profit':
                $query->orderByRaw("(deposit_approved_total - withdrawal_approved_total) {$desc}");
                break;
        }

        // Newest first breaks ties, so rows never shuffle across a page boundary.
        $query->orderBy('users.created_at', $desc)->orderBy('users.id', $desc);
    }
}
