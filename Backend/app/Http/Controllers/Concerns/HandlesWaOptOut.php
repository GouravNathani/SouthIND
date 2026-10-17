<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Support\WaHub\WaHubClient;
use App\Support\WaHub\WaHubNotifier;
use App\Support\WaHub\WaHubPhone;
use App\Support\WaHub\WaHubResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Exclude from WhatsApp" on the user page. The hub keeps the list per branch
 * number: a STOP reply lands there by itself (`stop_reply`), the admin's
 * switch writes `manual`. Excluded users get no event or campaign message from
 * that branch's number; a later START from the user only undoes `stop_reply`.
 *
 * The full number never goes back to the SPA (the phone may be masked there).
 * Needs ProxiesWaHub and a `$hub` WaHubClient on the controller.
 */
trait HandlesWaOptOut
{
    protected function optOutStatus(User $user): JsonResponse
    {
        $number = WaHubPhone::normalize($user->phone);
        if ($number === '' || !$user->branch_id) {
            return response()->json(self::noPhone());
        }

        return $this->proxy(
            fn () => $this->hub->contact(WaHubClient::connectionKey((int) $user->branch_id), $number),
            fn (array $data): array => self::optOutView($data),
        );
    }

    protected function updateOptOut(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['excluded' => ['required', 'boolean']]);

        $number = WaHubPhone::normalize($user->phone);
        if ($number === '' || !$user->branch_id) {
            return response()->json(['message' => 'This user has no phone number.', 'code' => 'no_phone'], 422);
        }

        $body = ['opted_out' => (bool) $data['excluded']];
        if ($actor = WaHubNotifier::actor($request->user())) {
            $body['actor'] = $actor;
        }

        return $this->proxy(
            fn (): WaHubResponse => $this->hub->setContact(WaHubClient::connectionKey((int) $user->branch_id), $number, $body),
            fn (array $data): array => self::optOutView($data),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{has_phone: bool, excluded: bool, excluded_at: ?string, reason: ?string}
     */
    private static function optOutView(array $data): array
    {
        $excluded = (bool) ($data['opted_out'] ?? false);

        return [
            'has_phone' => true,
            'excluded' => $excluded,
            'excluded_at' => $excluded && is_string($data['opted_out_at'] ?? null) ? $data['opted_out_at'] : null,
            // stop_reply = the user replied STOP · manual = an admin excluded them
            'reason' => $excluded && is_string($data['reason'] ?? null) ? $data['reason'] : null,
        ];
    }

    /** @return array{has_phone: bool, excluded: bool, excluded_at: null, reason: null} */
    private static function noPhone(): array
    {
        return ['has_phone' => false, 'excluded' => false, 'excluded_at' => null, 'reason' => null];
    }
}
