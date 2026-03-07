<?php

namespace App\Http\Requests;

use App\Models\Digest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'max_days' => ['nullable', 'integer', 'min:1'],
            'is_weekly_digest' => ['boolean'],
            'week_starts_on' => [
                'nullable',
                'string',
                Rule::in($this->allowedWeekStartDays()),
                'required_if:is_weekly_digest,true',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $weeklyDigestEnabled = $this->boolean('is_weekly_digest');
        $weekStartsOn = $this->normalizeWeekStartsOn($this->input('week_starts_on'));

        if (! $weeklyDigestEnabled) {
            $this->merge([
                'week_starts_on' => null,
            ]);

            return;
        }

        $this->merge([
            'week_starts_on' => $weekStartsOn,
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $feedUrl = trim((string) $this->input('feed_url', ''));
            $name = trim((string) $this->input('name', ''));

            if ($feedUrl === '') {
                return;
            }

            $feedUrlExists = Digest::query()
                ->where('feed_url', $feedUrl)
                ->exists();

            $nameExists = false;

            if ($name !== '') {
                $nameExists = Digest::query()
                    ->where('name', $name)
                    ->exists();
            }

            if ($feedUrlExists && ($name === '' || $nameExists)) {
                if ($name === '') {
                    $validator->errors()->add('feed_url', 'The feed URL has already been taken.');

                    return;
                }

                $validator->errors()->add('name', 'The feed name or URL must be unique.');
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function allowedWeekStartDays(): array
    {
        return [
            'Sunday',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'Saturday',
        ];
    }

    private function normalizeWeekStartsOn(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return ucfirst(strtolower($trimmed));
    }
}
