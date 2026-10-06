<?php

declare(strict_types=1);

namespace Dniccum\Linear\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Linear's redirect back after the user approves or cancels authorization.
 *
 * Every field is optional: Linear sends either a code or an error, and an
 * invalid callback is reported on the settings page rather than bounced back
 * as a validation failure.
 */
class OAuthCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:2048'],
            'error' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function state(): ?string
    {
        return $this->filled('state') ? $this->string('state')->toString() : null;
    }

    public function code(): ?string
    {
        return $this->filled('code') ? $this->string('code')->toString() : null;
    }

    public function error(): ?string
    {
        return $this->filled('error') ? $this->string('error')->toString() : null;
    }
}
