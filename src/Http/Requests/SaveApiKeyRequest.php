<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A personal Linear API key. The field is `api_key` (what the configuration
 * page's form posts); `apiKey` is accepted as well.
 */
class SaveApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('api_key') && $this->has('apiKey')) {
            $this->merge(['api_key' => $this->input('apiKey')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'api_key' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['api_key' => 'API key'];
    }

    public function apiKey(): string
    {
        return $this->string('api_key')->toString();
    }
}
