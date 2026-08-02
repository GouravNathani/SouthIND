<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppCloudClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppAccountController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = WhatsAppAccount::query()
            ->withCount('templates')
            ->latest()
            ->get()
            ->map(fn (WhatsAppAccount $a) => $this->serialize($a));

        return response()->json(['data' => $accounts]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'waba_id' => ['required', 'string', 'max:120'],
            'phone_number_id' => ['required', 'string', 'max:120', 'unique:whatsapp_accounts,phone_number_id'],
            'access_token' => ['required', 'string'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $account = WhatsAppAccount::create([
            'display_name' => $data['display_name'],
            'waba_id' => $data['waba_id'],
            'phone_number_id' => $data['phone_number_id'],
            'access_token' => $data['access_token'],
            'phone_number' => $data['phone_number'] ?? null,
            'status' => $data['status'] ?? WhatsAppAccount::STATUS_ACTIVE,
            'webhook_verify_token' => Str::random(32),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->serialize($account)], Response::HTTP_CREATED);
    }

    public function update(Request $request, WhatsAppAccount $whatsapp): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'string', 'max:120'],
            'waba_id' => ['sometimes', 'string', 'max:120'],
            'phone_number_id' => ['sometimes', 'string', 'max:120', 'unique:whatsapp_accounts,phone_number_id,' . $whatsapp->id],
            'access_token' => ['nullable', 'string'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        foreach (['display_name', 'waba_id', 'phone_number_id', 'phone_number', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $whatsapp->{$field} = $data[$field];
            }
        }
        if (!empty($data['access_token'])) {
            $whatsapp->access_token = $data['access_token'];
        }
        $whatsapp->save();

        return response()->json(['data' => $this->serialize($whatsapp)]);
    }

    public function destroy(WhatsAppAccount $whatsapp): JsonResponse
    {
        $whatsapp->delete();

        return response()->json(['message' => 'Account removed.']);
    }

    public function syncTemplates(WhatsAppAccount $whatsapp): JsonResponse
    {
        try {
            $templates = (new WhatsAppCloudClient($whatsapp))->fetchTemplates();
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $count = 0;
        foreach ($templates as $template) {
            $name = $template['name'] ?? null;
            if (!$name) {
                continue;
            }
            WhatsAppTemplate::updateOrCreate(
                [
                    'whatsapp_account_id' => $whatsapp->id,
                    'name' => $name,
                    'language' => $template['language'] ?? 'en_US',
                ],
                [
                    'category' => $template['category'] ?? null,
                    'status' => $template['status'] ?? null,
                    'components' => $template['components'] ?? null,
                    'synced_at' => now(),
                ],
            );
            $count++;
        }

        return response()->json(['message' => 'Templates synced.', 'synced_count' => $count]);
    }

    public function templates(Request $request): JsonResponse
    {
        $query = WhatsAppTemplate::query()->latest('synced_at');
        if ($accountId = $request->query('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $accountId);
        }

        $templates = $query->get()->map(fn (WhatsAppTemplate $t) => [
            'id' => $t->id,
            'whatsapp_account_id' => $t->whatsapp_account_id,
            'name' => $t->name,
            'language' => $t->language,
            'category' => $t->category,
            'status' => $t->status,
            'components' => $t->components,
        ]);

        return response()->json(['data' => $templates]);
    }

    private function serialize(WhatsAppAccount $account): array
    {
        return [
            'id' => $account->id,
            'display_name' => $account->display_name,
            'waba_id' => $account->waba_id,
            'phone_number_id' => $account->phone_number_id,
            'phone_number' => $account->phone_number,
            'status' => $account->status,
            'webhook_verify_token' => $account->webhook_verify_token,
            'has_token' => filled($account->getRawOriginal('access_token')),
            'templates_count' => $account->templates_count ?? $account->templates()->count(),
            'created_at' => $account->created_at,
        ];
    }
}
