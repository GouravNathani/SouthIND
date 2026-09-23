<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\WalletCharge;
use App\Support\Chat\ChatMedia;
use App\Support\PhoneMask;
use App\Support\Push\UserPushNotifier;
use App\Support\ResolvesBranch;
use App\Support\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SupportChatController extends Controller
{
    use ResolvesBranch;

    public function conversations(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        $query = SupportConversation::query()
            ->where('branch_id', $branchId)
            ->with('user.tags')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($status = $request->query('status')) {
            if (in_array($status, [SupportConversation::STATUS_OPEN, SupportConversation::STATUS_CLOSED], true)) {
                $query->where('status', $status);
            }
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            $query->whereHas('user', function ($q) use ($pattern) {
                $q->where('name', 'like', $pattern)
                    ->orWhere('phone', 'like', $pattern)
                    ->orWhere('unique_number', 'like', $pattern);
            });
        }

        if ($tagId = $request->query('tag_id')) {
            $query->whereHas('user.tags', fn ($q) => $q->where('tags.id', (int) $tagId));
        }

        // Polled every few seconds: cap to the most recently active threads so the
        // payload stays constant as the user base (and its conversations) grows.
        $conversations = $query->limit(200)->get()->map(fn (SupportConversation $c) => [
            'id' => $c->id,
            'status' => $c->status,
            'flagged' => (bool) $c->flagged,
            'awaiting_reply' => (bool) $c->awaiting_reply,
            'last_message_preview' => $c->last_message_preview,
            'last_message_at' => $c->last_message_at,
            'unread_count' => $c->admin_unread_count,
            'tags' => $this->serializeTags($c),
            'user' => $c->user ? [
                'id' => $c->user->id,
                'name' => $c->user->name,
                'phone' => PhoneMask::apply($c->user->phone),
                'unique_number' => $c->user->unique_number,
            ] : null,
        ]);

        return response()->json(['data' => $conversations]);
    }

    public function show(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->ensureOwnership($conversation, $request->user());

        $conversation->messages()
            ->where('sender_type', SupportMessage::SENDER_USER)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ($conversation->admin_unread_count > 0) {
            $conversation->admin_unread_count = 0;
            $conversation->save();
        }

        $conversation->load('user.tags');

        return response()->json([
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'status' => $conversation->status,
                    'flagged' => (bool) $conversation->flagged,
                    'awaiting_reply' => (bool) $conversation->awaiting_reply,
                    'tags' => $this->serializeTags($conversation),
                    'user' => $conversation->user ? [
                        'id' => $conversation->user->id,
                        'name' => $conversation->user->name,
                        'phone' => PhoneMask::apply($conversation->user->phone),
                        'unique_number' => $conversation->user->unique_number,
                    ] : null,
                ],
                // Deleted messages stay visible to admins (struck-through), so pull them too.
                'messages' => $conversation->messages()
                    ->withTrashed()
                    ->where('created_at', '>=', now()->subDays(SupportMessage::RETENTION_DAYS))
                    ->orderBy('id')
                    ->get()
                    ->map(fn (SupportMessage $m) => $this->serializeMessage($m))
                    ->all(),
            ],
        ]);
    }

    public function send(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->ensureOwnership($conversation, $request->user());

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

        $admin = $request->user();

        $message = $conversation->messages()->create([
            'sender_type' => SupportMessage::SENDER_ADMIN,
            'sender_id' => $admin->id,
            'body' => $body !== '' ? $body : null,
            'image_path' => $imagePath,
            'audio_path' => $audioPath,
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_message_preview' => $preview,
            // Support replied — clear the unreplied flag.
            'awaiting_reply' => false,
            'user_unread_count' => $conversation->user_unread_count + 1,
            'assigned_admin_id' => $conversation->assigned_admin_id ?: $admin->id,
        ])->save();

        // Charge the global wallet for this outgoing reply (1 paisa text / 5 paise image).
        app(WalletService::class)->chargeForSupportMessage($message, WalletCharge::DIRECTION_OUT);

        try {
            UserPushNotifier::notifySupportReply(
                (int) $conversation->user_id,
                $preview,
            );
        } catch (\Throwable $e) {
            Log::warning('Support reply user push failed.', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'data' => $this->serializeMessage($message),
        ], Response::HTTP_CREATED);
    }

    public function updateStatus(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->ensureOwnership($conversation, $request->user());

        $data = $request->validate([
            'status' => ['required', 'in:open,closed'],
        ]);

        $conversation->status = $data['status'];
        $conversation->save();

        return response()->json(['data' => ['id' => $conversation->id, 'status' => $conversation->status]]);
    }

    /**
     * Edit a message's text. The user sees the updated text; the panels show an
     * "edited" marker.
     */
    public function update(Request $request, SupportConversation $conversation, SupportMessage $message): JsonResponse
    {
        $this->ensureOwnership($conversation, $request->user());
        $this->ensureMessageInConversation($message, $conversation);

        if ($message->created_at && $message->created_at->lt(now()->subHours(SupportMessage::ADMIN_EDIT_WINDOW_HOURS))) {
            return response()->json([
                'message' => 'Edit window (' . SupportMessage::ADMIN_EDIT_WINDOW_HOURS . ' hours) has passed.',
            ], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $body = trim($data['body']);
        if ($body === '') {
            return response()->json(['message' => 'Message cannot be empty.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message->forceFill(['body' => $body, 'edited_at' => now()])->save();

        $this->refreshConversationMeta($conversation);

        return response()->json(['data' => $this->serializeMessage($message)]);
    }

    /**
     * Soft-delete a message: it disappears for the user but stays visible to
     * admins (struck-through), and its wallet charge is voided so the support
     * cost drops.
     */
    public function destroy(Request $request, SupportConversation $conversation, SupportMessage $message): JsonResponse
    {
        $this->ensureOwnership($conversation, $request->user());
        $this->ensureMessageInConversation($message, $conversation);

        if ($message->created_at && $message->created_at->lt(now()->subHours(SupportMessage::ADMIN_DELETE_WINDOW_HOURS))) {
            return response()->json([
                'message' => 'Delete window (' . SupportMessage::ADMIN_DELETE_WINDOW_HOURS . ' hours) has passed.',
            ], Response::HTTP_FORBIDDEN);
        }

        app(WalletService::class)->voidChargesFor($message);
        $message->delete();

        $this->refreshConversationMeta($conversation);

        return response()->json(['data' => $this->serializeMessage($message)]);
    }

    protected function ensureOwnership(SupportConversation $conversation, $actor): void
    {
        $branchId = $this->resolveBranchId($actor);

        if ((int) $conversation->branch_id !== (int) $branchId) {
            abort(Response::HTTP_NOT_FOUND, 'Conversation not found.');
        }
    }

    private function ensureMessageInConversation(SupportMessage $message, SupportConversation $conversation): void
    {
        if ((int) $message->conversation_id !== (int) $conversation->id) {
            abort(Response::HTTP_NOT_FOUND, 'Message not found.');
        }
    }

    /**
     * Re-point the conversation's preview/last-activity at the newest surviving
     * message after an edit or delete.
     */
    private function refreshConversationMeta(SupportConversation $conversation): void
    {
        $last = $conversation->messages()->latest('id')->first();

        if (!$last) {
            $conversation->forceFill(['last_message_preview' => null])->save();

            return;
        }

        $preview = $last->body !== null && $last->body !== ''
            ? mb_substr($last->body, 0, 120)
            : ($last->image_path ? '📷 Photo' : ($last->audio_path ? '🎤 Voice message' : ''));

        $conversation->forceFill([
            'last_message_at' => $last->created_at,
            'last_message_preview' => $preview,
        ])->save();
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
            'edited_at' => $message->edited_at,
            'deleted' => $message->trashed(),
            'deleted_at' => $message->deleted_at,
        ];
    }

    private function serializeTags(SupportConversation $conversation): array
    {
        $tags = $conversation->user?->tags;
        if (!$tags) {
            return [];
        }

        return $tags->map(fn ($tag) => [
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color,
        ])->values()->all();
    }
}
