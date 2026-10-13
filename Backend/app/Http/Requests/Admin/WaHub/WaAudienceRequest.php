<?php

namespace App\Http\Requests\Admin\WaHub;

use App\Support\WaHub\CampaignAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Campaign audience: users with ANY of these tags, or these users. */
class WaAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audience' => ['required', 'array'],
            'audience.type' => ['required', Rule::in(['tags', 'users'])],
            'audience.tag_ids' => ['required_if:audience.type,tags', 'array', 'max:100'],
            'audience.tag_ids.*' => ['integer'],
            'audience.user_ids' => ['required_if:audience.type,users', 'array', 'max:' . CampaignAudience::MAX_RECIPIENTS],
            'audience.user_ids.*' => ['integer'],
        ];
    }
}
