<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ProxiesWaHub;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WaHub\WaAudienceRequest;
use App\Http\Requests\Admin\WaHub\WaCampaignRequest;
use App\Http\Requests\Admin\WaHub\WaConnectionRequest;
use App\Http\Requests\Admin\WaHub\WaEventRequest;
use App\Http\Requests\Admin\WaHub\WaTemplateRequest;
use App\Http\Requests\Admin\WaHub\WaTestRequest;
use App\Models\Branch;
use App\Support\PhoneMask;
use App\Support\ResolvesBranch;
use App\Support\WaHub\CampaignAudience;
use App\Support\WaHub\WaHubClient;
use App\Support\WaHub\WaHubNotifier;
use App\Support\WaHub\WaHubPhone;
use App\Support\WaHub\WaHubResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WhatsApp (WA Hub) for a branch admin: link the branch's own number, set the
 * event texts, send campaigns to the branch's users and see the history.
 *
 * Everything is scoped to the admin's branch: the hub connection key is always
 * `branch-{branch_id}`, never taken from the request.
 */
class WaHubController extends Controller
{
    use ProxiesWaHub;
    use ResolvesBranch;

    /** Message list filters passed through to the hub (connection is forced). */
    private const MESSAGE_FILTERS = ['kind', 'event', 'status', 'campaign_id', 'ref_user_id', 'q', 'from', 'to', 'page', 'per_page'];

    public function __construct(private readonly WaHubClient $hub)
    {
    }

    // ---- Connection -------------------------------------------------------

    /** The branch's connection, created on first visit with the branch name as label. */
    public function connection(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $key = WaHubClient::connectionKey($branch->id);

        return $this->proxy(function () use ($branch, $key): WaHubResponse {
            $response = $this->hub->connection($key);

            $missing = $response->status === Response::HTTP_NOT_FOUND && $response->error() === 'connection_not_found';
            $renamed = $response->successful() && ($response->data['label'] ?? null) !== $branch->name;

            return $missing || $renamed
                ? $this->hub->saveConnection($key, ['label' => $branch->name])
                : $response;
        });
    }

    public function updateConnection(WaConnectionRequest $request): JsonResponse
    {
        $branch = $this->branch($request);
        $data = ['label' => $branch->name];

        foreach (['header', 'footer'] as $field) {
            if ($request->exists($field)) {
                $data[$field] = (string) $request->validated($field);
            }
        }

        return $this->proxy(fn () => $this->hub->saveConnection(WaHubClient::connectionKey($branch->id), $data));
    }

    public function link(Request $request): JsonResponse
    {
        $branch = $this->branch($request);

        return $this->proxy(fn () => $this->hub->link(WaHubClient::connectionKey($branch->id), ['label' => $branch->name]));
    }

    public function unlink(Request $request): JsonResponse
    {
        return $this->proxy(fn () => $this->hub->unlink($this->connectionKey($request)));
    }

    // ---- Events -----------------------------------------------------------

    public function events(Request $request): JsonResponse
    {
        return $this->proxy(fn () => $this->hub->events($this->connectionKey($request)));
    }

    public function updateEvent(WaEventRequest $request, string $event): JsonResponse
    {
        // validated() keeps `body: null` (reset to default) apart from "not sent".
        $data = $request->validated();

        // The hub takes a real JSON boolean and refuses "1" / "0" strings.
        if (array_key_exists('enabled', $data)) {
            $data['enabled'] = (bool) $data['enabled'];
        }

        return $this->proxy(fn () => $this->hub->updateEvent($this->connectionKey($request), $event, $data));
    }

    public function previewEvent(Request $request, string $event): JsonResponse
    {
        $data = $request->validate([
            'body' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return $this->proxy(fn () => $this->hub->previewEvent($this->connectionKey($request), $event, array_filter(
            $data,
            fn ($value) => $value !== null,
        )));
    }

    /** A test message to `to`, or to the admin's own phone when none is given. */
    public function test(WaTestRequest $request): JsonResponse
    {
        $data = $request->validated();
        $to = WaHubPhone::normalize(filled($data['to'] ?? null) ? $data['to'] : $request->user()->phone);

        if ($to === '') {
            return response()->json([
                'message' => 'Enter the WhatsApp number to send the test to.',
                'errors' => ['to' => ['Enter the WhatsApp number to send the test to.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->proxy(fn () => $this->hub->test($this->connectionKey($request), [
            'to' => $to,
            'text' => $data['text'],
        ]));
    }

    // ---- Saved templates --------------------------------------------------

    public function templates(Request $request): JsonResponse
    {
        return $this->proxy(fn () => $this->hub->templates($this->connectionKey($request)));
    }

    public function storeTemplate(WaTemplateRequest $request): JsonResponse
    {
        return $this->proxy(fn () => $this->hub->createTemplate($this->connectionKey($request), $request->validated()));
    }

    public function updateTemplate(WaTemplateRequest $request, int $id): JsonResponse
    {
        return $this->proxy(fn () => $this->hub->updateTemplate($this->connectionKey($request), $id, $request->validated()));
    }

    public function destroyTemplate(Request $request, int $id): JsonResponse
    {
        return $this->proxy(fn () => $this->hub->deleteTemplate($this->connectionKey($request), $id));
    }

    // ---- Campaigns --------------------------------------------------------

    /** Who would receive it and what it costs — computed here, nothing is sent. */
    public function previewCampaign(WaAudienceRequest $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());

        return response()->json(CampaignAudience::preview($branchId, $request->validated('audience')));
    }

    public function storeCampaign(WaCampaignRequest $request): JsonResponse
    {
        $branchId = $this->resolveBranchId($request->user());
        $data = $request->validated();
        $audience = CampaignAudience::resolve($branchId, $data['audience']);

        if ($audience['total'] === 0) {
            return response()->json([
                'error' => 'no_recipients',
                'message' => 'No active user with a phone number in this audience.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($audience['total'] > CampaignAudience::MAX_RECIPIENTS) {
            return response()->json([
                'error' => 'too_many_recipients',
                'message' => 'A campaign can go to at most ' . number_format(CampaignAudience::MAX_RECIPIENTS) . ' users. Narrow the audience.',
                'total' => $audience['total'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload = [
            'connection' => WaHubClient::connectionKey($branchId),
            'title' => $data['title'],
        ];

        if (filled($data['body'] ?? null)) {
            $payload['body'] = $data['body'];
        }
        if (!empty($data['template_id'])) {
            $payload['template_id'] = (int) $data['template_id'];
        }

        $payload['audience_label'] = $audience['label'];

        if (filled($data['send_at'] ?? null)) {
            // A time without offset is IST (the panel's clock); the hub gets UTC.
            $payload['send_at'] = Carbon::parse($data['send_at'], config('app.timezone'))->utc()->toIso8601ZuluString();
        }

        $payload['recipients'] = $audience['recipients'];

        if ($actor = WaHubNotifier::actor($request->user())) {
            $payload['actor'] = $actor;
        }

        return $this->proxy(fn () => $this->hub->createCampaign($payload));
    }

    public function campaigns(Request $request): JsonResponse
    {
        return $this->proxy(fn () => $this->hub->campaigns([
            ...$this->hubFilters($request, ['page', 'per_page']),
            'connection' => $this->connectionKey($request),
        ]));
    }

    public function campaign(Request $request, int $id): JsonResponse
    {
        $key = $this->connectionKey($request);

        return $this->proxy(function () use ($id, $key) {
            $response = $this->hub->campaign($id);

            return $this->ownCampaign($response, $key) ? $response : $this->campaignNotFound();
        });
    }

    public function cancelCampaign(Request $request, int $id): JsonResponse
    {
        $key = $this->connectionKey($request);

        return $this->proxy(function () use ($id, $key) {
            $response = $this->hub->campaign($id);

            if (!$this->ownCampaign($response, $key)) {
                return $this->campaignNotFound();
            }

            return $this->hub->cancelCampaign($id);
        });
    }

    // ---- History & usage --------------------------------------------------

    public function messages(Request $request): JsonResponse
    {
        $query = [
            ...$this->hubFilters($request, self::MESSAGE_FILTERS),
            'connection' => $this->connectionKey($request),
        ];

        return $this->proxy(fn () => $this->hub->messages($query), function (array $data): array {
            if (PhoneMask::enabled() && is_array($data['data'] ?? null)) {
                $data['data'] = array_map(fn ($message) => is_array($message) ? self::maskMessage($message) : $message, $data['data']);
            }

            return $data;
        });
    }

    public function usage(Request $request): JsonResponse
    {
        return $this->proxy(fn () => $this->hub->usage(['connection' => $this->connectionKey($request)]));
    }

    // ---- Helpers ----------------------------------------------------------

    private function branch(Request $request): Branch
    {
        return Branch::query()->findOrFail($this->resolveBranchId($request->user()));
    }

    private function connectionKey(Request $request): string
    {
        return WaHubClient::connectionKey($this->resolveBranchId($request->user()));
    }

    /** Another branch's campaign is answered exactly like a missing one. */
    private function ownCampaign(WaHubResponse $response, string $key): bool
    {
        return !$response->successful() || ($response->data['connection'] ?? null) === $key;
    }

    private function campaignNotFound(): JsonResponse
    {
        return response()->json(['error' => 'campaign_not_found'], Response::HTTP_NOT_FOUND);
    }

    /**
     * `91******3210` for branch admins while mask_user_phone is on — in the
     * number and in the text itself (welcome / MPIN texts can carry it).
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private static function maskMessage(array $message): array
    {
        $to = (string) ($message['to'] ?? '');
        if ($to === '') {
            return $message;
        }

        $masked = WaHubPhone::mask($to);
        $message['to'] = $masked;

        // The text carries the number as the user typed it ("+91 98765-43210",
        // "098765 43210", …): match its last 10 digits with any spaces /
        // dashes in between and an optional +91 / 91 / 0 in front.
        $digits = preg_replace('/\D+/', '', $to) ?? '';
        if (is_string($message['body'] ?? null) && strlen($digits) >= 6) {
            $tail = str_split(substr($digits, -10));
            $pattern = '/(?<!\d)(?:\+?91[\s-]?|0)?' . implode('[\s-]?', $tail) . '(?!\d)/';
            $message['body'] = preg_replace($pattern, $masked, $message['body']) ?? $message['body'];
        }

        return $message;
    }
}
