<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeedDigestRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:150'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $routeDate = $this->route('date');

        if (is_string($routeDate) && $routeDate !== '') {
            $this->merge([
                'date' => $routeDate,
            ]);
        }
    }
}
