<?php

namespace App\Http\Requests\Admin\WaHub;

/** A campaign: the audience plus a title and either a text or a saved template. */
class WaCampaignRequest extends WaAudienceRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'required_without:template_id', 'string', 'max:2000'],
            'template_id' => ['nullable', 'integer', 'min:1'],
            'send_at' => ['nullable', 'date'],
        ];
    }
}
