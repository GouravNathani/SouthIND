<?php

namespace App\Support\Chat;

use App\Models\AppSetting;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Support\Push\UserPushNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SupportAutoReplyDispatcher
{
    /**
     * A chat is considered "cold" (and worth an auto-reply) when there has
     * been no message / auto-reply activity within this many minutes. This
     * keeps the acknowledgement to first / re-engagement messages only and
     * prevents it firing on every message in an active conversation.
     */
    public const COOLDOWN_MINUTES = 15;

    /**
     * Send a one-off automated acknowledgement to the user when they open a
     * new or cold support chat, if the branch has auto-reply configured.
     *
     * @param  SupportConversation  $conversation  Freshly updated with the user's message.
     * @param  Carbon|null  $previousLastMessageAt  The conversation's last_message_at BEFORE the user's new message.
     */
    public static function maybeDispatch(SupportConversation $conversation, ?Carbon $previousLastMessageAt): void
    {
        $cooldownStart = now()->subMinutes(self::COOLDOWN_MINUTES);

        // Cold = first ever message, or a gap larger than the cooldown window.
        $isCold = $previousLastMessageAt === null || $previousLastMessageAt->lt($cooldownStart);

        // Guard against double-sending on a burst of user messages.
        $notRecentlyReplied = $conversation->last_auto_reply_at === null
            || $conversation->last_auto_reply_at->lt($cooldownStart);

        if (!$isCold || !$notRecentlyReplied) {
            return;
        }

        $setting = AppSetting::query()
            ->where('branch_id', $conversation->branch_id)
            ->latest('id')
            ->first();

        if (!$setting || !$setting->support_auto_reply_enabled) {
            return;
        }

        $text = trim((string) $setting->support_auto_reply_text);
        if ($text === '') {
            return;
        }

        $conversation->messages()->create([
            'sender_type' => SupportMessage::SENDER_ADMIN,
            'sender_id' => null,
            'body' => $text,
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_message_preview' => mb_substr($text, 0, 120),
            'user_unread_count' => $conversation->user_unread_count + 1,
            'last_auto_reply_at' => now(),
        ])->save();

        try {
            UserPushNotifier::notifySupportReply((int) $conversation->user_id, $text);
        } catch (\Throwable $e) {
            Log::warning('Support auto-reply user push failed.', ['error' => $e->getMessage()]);
        }
    }
}
