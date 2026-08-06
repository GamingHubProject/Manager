<?php

namespace Azuriom\Plugin\GamingHubManager\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveExtensionSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gaminghub.manager.sources') ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['registry', 'github'])],
            'name' => ['required', 'string', 'max:150'],
            'url' => ['required', 'url:https', 'max:2000'],
            'acknowledge' => ['accepted'],
            'trusted' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
            'allow_prereleases' => ['sometimes', 'boolean'],
            'allow_private_host' => ['sometimes', 'boolean'],
            'release_asset' => ['nullable', 'string', 'max:255', 'regex:/\.zip$/i'],
            'checksum_asset' => ['nullable', 'string', 'max:255'],
        ];
    }
}
