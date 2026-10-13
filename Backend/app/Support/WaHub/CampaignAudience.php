<?php

namespace App\Support\WaHub;

use App\Models\Tag;
use App\Models\User;

/**
 * Who a campaign goes to, resolved inside one branch.
 *
 * Audience = users carrying ANY of the given tags, or an explicit list of
 * user ids. Tags and users of another branch are ignored. Only active users
 * with a phone are sent to, and a number shared by two accounts gets one
 * message (numbers are compared normalised, the way the hub sees them).
 */
class CampaignAudience
{
    /** The hub accepts at most this many recipients per campaign. */
    public const MAX_RECIPIENTS = 5000;

    private const SAMPLE_SIZE = 5;

    /**
     * @param  array{type: string, tag_ids?: list<int|string>, user_ids?: list<int|string>}  $audience
     * @return array{
     *     total: int,
     *     skipped: array{no_phone: int, duplicate: int, inactive: int},
     *     label: string,
     *     recipients: list<array{to: string, name: string, vars: array{play_id: string}, ref_user_id: string}>,
     *     sample: list<array{id: int, name: string, phone_masked: string}>
     * }
     */
    public static function resolve(int $branchId, array $audience): array
    {
        $result = [
            'total' => 0,
            'skipped' => ['no_phone' => 0, 'duplicate' => 0, 'inactive' => 0],
            'label' => '',
            'recipients' => [],
            'sample' => [],
        ];

        $query = User::query()
            ->where('branch_id', $branchId)
            ->select(['id', 'name', 'phone', 'play_id', 'status']);

        if (($audience['type'] ?? null) === 'tags') {
            $tags = Tag::query()
                ->where('branch_id', $branchId)
                ->whereIn('id', self::ids($audience['tag_ids'] ?? []))
                ->orderBy('name')
                ->get(['id', 'name']);

            $result['label'] = 'Tags: ' . $tags->pluck('name')->implode(', ');

            if ($tags->isEmpty()) {
                return $result;
            }

            $query->whereHas('tags', fn ($tagQuery) => $tagQuery->whereIn('tags.id', $tags->pluck('id')->all()));
        } else {
            $userIds = self::ids($audience['user_ids'] ?? []);

            if ($userIds === []) {
                $result['label'] = '0 users';

                return $result;
            }

            $query->whereIn('id', $userIds);
        }

        $matched = 0;
        $seen = [];

        foreach ($query->lazyById(1000) as $user) {
            $matched++;

            if ($user->status !== User::STATUS_ACTIVE) {
                $result['skipped']['inactive']++;

                continue;
            }

            $to = WaHubPhone::normalize($user->phone);

            if ($to === '') {
                $result['skipped']['no_phone']++;

                continue;
            }

            if (isset($seen[$to])) {
                $result['skipped']['duplicate']++;

                continue;
            }

            $seen[$to] = true;
            $result['total']++;

            // Past the limit only the count matters (the request is refused).
            if (count($result['recipients']) < self::MAX_RECIPIENTS) {
                $result['recipients'][] = [
                    'to' => $to,
                    'name' => (string) $user->name,
                    'vars' => ['play_id' => (string) $user->play_id],
                    'ref_user_id' => (string) $user->id,
                ];
            }

            if (count($result['sample']) < self::SAMPLE_SIZE) {
                $result['sample'][] = [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'phone_masked' => WaHubPhone::mask($to),
                ];
            }
        }

        if (($audience['type'] ?? null) !== 'tags') {
            $result['label'] = $matched === 1 ? '1 user' : "{$matched} users";
        }

        return $result;
    }

    /**
     * The preview the panel shows before sending: 1 coin per message sent.
     *
     * @param  array{type: string, tag_ids?: list<int|string>, user_ids?: list<int|string>}  $audience
     * @return array<string, mixed>
     */
    public static function preview(int $branchId, array $audience): array
    {
        $resolved = self::resolve($branchId, $audience);

        return [
            'total' => $resolved['total'],
            'skipped' => $resolved['skipped'],
            'coins' => $resolved['total'],
            'sample' => $resolved['sample'],
            'max_recipients' => self::MAX_RECIPIENTS,
        ];
    }

    /**
     * @param  mixed  $ids
     * @return list<int>
     */
    private static function ids(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
    }
}
