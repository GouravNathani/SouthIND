<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WalletCharge;
use App\Support\Push\AdminPushNotifier;
use App\Support\Wallet\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public webhook for Meta WhatsApp Cloud API. Meta sends one webhook URL for the
 * whole app; the payload's phone_number_id selects the matching account.
 * Note: PHP rewrites `hub.mode` query keys to `hub_mode`.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token && WhatsAppAccount::query()->where('webhook_verify_token', $token)->exists()) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request): JsonResponse
    {
        try {
            foreach ((array) $request->input('entry', []) as $entry) {
                foreach (($entry['changes'] ?? []) as $change) {
                    $value = $change['value'] ?? [];
                    $phoneNumberId = data_get($value, 'metadata.phone_number_id');
                    $account = $phoneNumberId
                        ? WhatsAppAccount::query()->where('phone_number_id', $phoneNumberId)->first()
                        : null;

                    if (!$account) {
                        continue;
                    }

                    $contactName = data_get($value, 'contacts.0.profile.name');

                    foreach (($value['messages'] ?? []) as $message) {
                        $this->storeInbound($account, $message, $contactName);
                    }

                    foreach (($value['statuses'] ?? []) as $status) {
                        $this->updateStatus($status);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp webhook processing failed.', ['error' => $e->getMessage()]);
        }

        return response()->json(['received' => true]);
    }

    private function storeInbound(WhatsAppAccount $account, array $message, ?string $contactName): void
    {
        $from = preg_replace('/\D+/', '', $message['from'] ?? '') ?? '';
        if ($from === '') {
            return;
        }

        $conversation = WhatsAppConversation::firstOrCreate(
            ['whatsapp_account_id' => $account->id, 'contact_phone' => $from],
        );

        $type = $message['type'] ?? 'text';
        $body = data_get($message, 'text.body')
            ?? data_get($message, 'button.text')
            ?? data_get($message, 'image.caption');
        $preview = $body ?: ('[' . $type . ']');

        $record = $conversation->messages()->create([
            'whatsapp_account_id' => $account->id,
            'wa_message_id' => $message['id'] ?? null,
            'direction' => WhatsAppMessage::DIRECTION_INBOUND,
            'type' => $type,
            'body' => $body,
            'status' => 'delivered',
        ]);

        // Charge the wallet for this incoming WhatsApp message (whatsapp_in_cost).
        app(WalletService::class)->chargeForWhatsAppMessage($record, WalletCharge::DIRECTION_IN);

        $conversation->forceFill([
            'contact_name' => $contactName ?: $conversation->contact_name,
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'last_message_preview' => mb_substr((string) $preview, 0, 120),
            'unread_count' => $conversation->unread_count + 1,
        ])->save();

        try {
            AdminPushNotifier::notifySuperAdmins(
                'New WhatsApp message',
                trim(($contactName ? $contactName . ': ' : '') . ((string) $preview)),
                '/whatsapp/inbox',
            );
        } catch (\Throwable $e) {
            Log::warning('WhatsApp inbound push failed.', ['error' => $e->getMessage()]);
        }
    }

    private function updateStatus(array $status): void
    {
        $id = $status['id'] ?? null;
        $state = $status['status'] ?? null;
        if (!$id || !$state) {
            return;
        }

        WhatsAppMessage::query()->where('wa_message_id', $id)->update(['status' => $state]);
    }
}
