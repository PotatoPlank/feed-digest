<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DigestStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'feed_url' => ['required', 'url'],
            'name' => ['nullable', 'string', 'max:150'],
            'timezone' => ['nullable', 'timezone'],
            'filters' => ['nullable', 'array'],
            'filters.*' => ['string'],
            'only_prior_to_today' => ['boolean'],
        ];
    }
}
