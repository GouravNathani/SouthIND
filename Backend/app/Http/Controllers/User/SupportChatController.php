<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\WalletCharge;
use App\Support\Chat\ChatMedia;
use App\Support\Chat\SharedContactDetector;
use App\Support\Chat\SupportAutoReplyDispatcher;
use App\Support\Push\AdminPushNotifier;
use App\Support\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SupportChatController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->conversationFor($user);

        // Mark admin replies as read for the user.
        $conversation->messages()
            ->where('sender_type', SupportMessage::SENDER_ADMIN)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($conversation->user_unread_count > 0) {
            $conversation->user_unread_count = 0;
            $conversation->save();
        }

        return response()->json([
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'status' => $conversation->status,
                ],
                'messages' => $this->serializeMessages($conversation),
            ],
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'image' => ['nullable', 'string'],
            'audio' => ['nullable', 'string'],
        ]);

        $body = isset($data['body']) ? trim($data['body']) : '';
        $imagePath = ChatMedia::storeBase64($data['image'] ?? null);
        $audioPath = ChatMedia::storeBase64Audio($data['audio'] ?? null);

        if ($body === '' && !$imagePath && !$audioPath) {
            return response()->json(['message' => 'Message cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $preview = $body !== ''
            ? mb_substr($body, 0, 120)
            : ($imagePath ? '📷 Photo' : '🎤 Voice message');

        $user = $request->user();
        $conversation = $this->conversationFor($user);

        // Snapshot before the user's message bumps last_message_at — the
        // auto-reply dispatcher uses it to decide whether the chat is cold.
        $previousLastMessageAt = $conversation->last_message_at;

        $message = $conversation->messages()->create([
            'sender_type' => SupportMessage::SENDER_USER,
            'sender_id' => $user->id,
            'body' => $body !== '' ? $body : null,
            'image_path' => $imagePath,
            'audio_path' => $audioPath,
        ]);

        $conversation->forceFill([
            'status' => SupportConversation::STATUS_OPEN,
            'last_message_at' => now(),
            'last_message_preview' => $preview,
            // User just wrote — support now owes a reply.
            'awaiting_reply' => true,
            'admin_unread_count' => $conversation->admin_unread_count + 1,
            // Flag (sticky) once the user shares a contact number so support staff notice.
            'flagged' => $conversation->flagged || SharedContactDetector::containsNumber($body),
        ])->save();

        // Charge the global wallet for this incoming message (1 paisa text / 5 paise image).
        app(WalletService::class)->chargeForSupportMessage($message, WalletCharge::DIRECTION_IN);

        try {
            AdminPushNotifier::notifySupportMessage(
                $user->branch_id,
                $user->name,
                $preview,
            );
        } catch (\Throwable $e) {
            Log::warning('Support message admin push failed.', ['error' => $e->getMessage()]);
        }

        // Fire a configured acknowledgement on new/cold chats (never on a burst).
        SupportAutoReplyDispatcher::maybeDispatch($conversation, $previousLastMessageAt);

        return response()->json([
            'data' => $this->serializeMessage($message),
        ], Response::HTTP_CREATED);
    }

    private function conversationFor($user): SupportConversation
    {
        return SupportConversation::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['branch_id' => $user->branch_id, 'status' => SupportConversation::STATUS_OPEN],
        );
    }

    private function serializeMessages(SupportConversation $conversation): array
    {
        return $conversation->messages()
            ->where('created_at', '>=', now()->subDays(SupportMessage::USER_VISIBLE_DAYS))
            ->orderBy('id')
            ->get()
            ->map(fn (SupportMessage $m) => $this->serializeMessage($m))
            ->all();
    }

    private function serializeMessage(SupportMessage $message): array
    {
        return [
            'id' => $message->id,
            'sender_type' => $message->sender_type,
            'body' => $message->body,
            'image_url' => ChatMedia::publicUrl($message->image_path),
            'audio_url' => ChatMedia::publicUrl($message->audio_path),
            'created_at' => $message->created_at,
            'edited' => $message->edited_at !== null,
        ];
    }
}
