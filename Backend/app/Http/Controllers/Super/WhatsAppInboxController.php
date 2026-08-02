<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WalletCharge;
use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Support\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppInboxController extends Controller
{
    public function conversations(Request $request): JsonResponse
    {
        $query = WhatsAppConversation::query()
            ->with('account:id,display_name')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($accountId = $request->query('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $accountId);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $pattern = '%' . $search . '%';
            $query->where(function ($q) use ($pattern) {
                $q->where('contact_phone', 'like', $pattern)
                    ->orWhere('contact_name', 'like', $pattern);
            });
        }

        $conversations = $query->limit(200)->get()->map(fn (WhatsAppConversation $c) => [
            'id' => $c->id,
            'whatsapp_account_id' => $c->whatsapp_account_id,
            'account_name' => $c->account?->display_name,
            'contact_phone' => $c->contact_phone,
            'contact_name' => $c->contact_name,
            'last_message_preview' => $c->last_message_preview,
            'last_message_at' => $c->last_message_at,
            'last_inbound_at' => $c->last_inbound_at,
            'unread_count' => $c->unread_count,
        ]);

        return response()->json(['data' => $conversations]);
    }

    public function conversation(WhatsAppConversation $conversation): JsonResponse
    {
        if ($conversation->unread_count > 0) {
            $conversation->unread_count = 0;
            $conversation->save();
        }

        $messages = $conversation->messages()
            ->orderBy('id')
            ->get()
            ->map(fn (WhatsAppMessage $m) => $this->serializeMessage($m));

        $withinWindow = $conversation->last_inbound_at
            && $conversation->last_inbound_at->greaterThan(now()->subHours(24));

        return response()->json([
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'whatsapp_account_id' => $conversation->whatsapp_account_id,
                    'contact_phone' => $conversation->contact_phone,
                    'contact_name' => $conversation->contact_name,
                    'within_24h_window' => (bool) $withinWindow,
                ],
                'messages' => $messages,
            ],
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'whatsapp_account_id' => ['required', 'exists:whatsapp_accounts,id'],
            'to' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:4000'],
            'template_name' => ['nullable', 'string', 'max:512'],
            'language' => ['nullable', 'string', 'max:20'],
            'components' => ['nullable', 'array'],
        ]);

        $account = WhatsAppAccount::findOrFail($data['whatsapp_account_id']);
        $to = preg_replace('/\D+/', '', $data['to']) ?? '';
        $isTemplate = !empty($data['template_name']);
        $message = isset($data['message']) ? trim($data['message']) : '';

        if (!$isTemplate && $message === '') {
            return response()->json(['message' => 'Message or template required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $conversation = WhatsAppConversation::firstOrCreate(
            ['whatsapp_account_id' => $account->id, 'contact_phone' => $to],
        );

        // 24-hour customer-service window: free-form text only within 24h of the
        // contact's last inbound message; otherwise a template is required.
        if (!$isTemplate) {
            $withinWindow = $conversation->last_inbound_at
                && $conversation->last_inbound_at->greaterThan(now()->subHours(24));
            if (!$withinWindow) {
                return response()->json([
                    'message' => 'Outside the 24-hour window. Use an approved template to start the conversation.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $client = new WhatsAppCloudClient($account);

        try {
            if ($isTemplate) {
                $result = $client->sendTemplate(
                    $to,
                    $data['template_name'],
                    $data['language'] ?? 'en_US',
                    $data['components'] ?? [],
                );
            } else {
                $result = $client->sendText($to, $message);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $waMessageId = data_get($result, 'messages.0.id');

        $record = $conversation->messages()->create([
            'whatsapp_account_id' => $account->id,
            'wa_message_id' => $waMessageId,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => $isTemplate ? 'template' : 'text',
            'body' => $isTemplate ? null : $message,
            'template_name' => $isTemplate ? $data['template_name'] : null,
            'status' => 'sent',
            'sent_by' => $request->user()->id,
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_message_preview' => $isTemplate ? '📄 ' . $data['template_name'] : mb_substr($message, 0, 120),
        ])->save();

        // Charge the wallet for this outgoing WhatsApp message (whatsapp_out_cost).
        app(WalletService::class)->chargeForWhatsAppMessage($record, WalletCharge::DIRECTION_OUT);

        return response()->json(['data' => $this->serializeMessage($record)], Response::HTTP_CREATED);
    }

    private function serializeMessage(WhatsAppMessage $message): array
    {
        return [
            'id' => $message->id,
            'direction' => $message->direction,
            'type' => $message->type,
            'body' => $message->body,
            'media_url' => $message->media_path,
            'template_name' => $message->template_name,
            'status' => $message->status,
            'created_at' => $message->created_at,
        ];
    }
}
