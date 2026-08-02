<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper around the WhatsApp Cloud API (Meta Graph API) for one account.
 */
class WhatsAppCloudClient
{
    public function __construct(private WhatsAppAccount $account)
    {
    }

    public function sendText(string $to, string $body): array
    {
        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ]);
    }

    /**
     * @param array<int, mixed> $components
     */
    public function sendTemplate(string $to, string $name, string $language, array $components = []): array
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];
        if (!empty($components)) {
            $template['components'] = $components;
        }

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchTemplates(): array
    {
        $response = Http::withToken($this->account->access_token)
            ->acceptJson()
            ->timeout(20)
            ->get($this->base() . '/' . $this->account->waba_id . '/message_templates', ['limit' => 200]);

        $json = $response->json() ?? [];

        if (!$response->successful()) {
            throw new RuntimeException((string) data_get($json, 'error.message', 'Failed to fetch templates.'));
        }

        return $json['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postMessage(array $payload): array
    {
        $response = Http::withToken($this->account->access_token)
            ->acceptJson()
            ->timeout(20)
            ->post($this->base() . '/' . $this->account->phone_number_id . '/messages', $payload);

        $json = $response->json() ?? [];

        if (!$response->successful()) {
            throw new RuntimeException((string) data_get($json, 'error.message', 'WhatsApp send failed.'));
        }

        return $json;
    }

    private function base(): string
    {
        $url = rtrim((string) config('services.whatsapp.base_url'), '/');
        $version = (string) config('services.whatsapp.api_version');

        return $url . '/' . $version;
    }
}
