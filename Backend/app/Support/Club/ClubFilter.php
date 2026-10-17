<?php

namespace App\Support\Club;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The `club_id` filter of the users lists: a club id, or "none" for users
 * below every club.
 */
class ClubFilter
{
    public static function apply(Builder $query, Request $request): void
    {
        $club = (string) $request->query('club_id', '');

        if ($club === 'none') {
            $query->whereDoesntHave('clubMembership');
        } elseif ($club !== '' && ctype_digit($club)) {
            $query->whereHas('clubMembership', fn (Builder $q) => $q->where('club_id', (int) $club));
        }
    }

    /** The `club` field of a user resource, or null when not in a club. */
    public static function resource($user): ?array
    {
        $membership = $user->clubMembership;
        if (! $membership || ! $membership->club) {
            return null;
        }

        return [
            'id' => $membership->club->id,
            'name' => $membership->club->name,
            'color' => $membership->club->color,
            'active_days' => (int) $membership->active_days,
        ];
    }
}
