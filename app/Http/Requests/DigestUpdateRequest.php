<?php

namespace App\Http\Requests;

use App\Models\Digest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DigestUpdateRequest extends FormRequest
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
            'feed_url' => ['sometimes', 'url'],
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
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('week_starts_on')) {
            $this->merge([
                'week_starts_on' => $this->normalizeWeekStartsOn($this->input('week_starts_on')),
            ]);
        }

        if ($this->has('is_weekly_digest') && ! $this->boolean('is_weekly_digest')) {
            $this->merge([
                'week_starts_on' => null,
            ]);
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->resolvedWeeklyDigestEnabled()) {
                return;
            }

            if ($this->resolvedWeekStartsOn() !== null) {
                return;
            }

            $validator->errors()->add(
                'week_starts_on',
                'The week starts on field is required when weekly digest is enabled.'
            );
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

    private function resolvedWeeklyDigestEnabled(): bool
    {
        if ($this->has('is_weekly_digest')) {
            return $this->boolean('is_weekly_digest');
        }

        $digest = $this->route('digest');

        if (! $digest instanceof Digest) {
            return false;
        }

        return (bool) $digest->is_weekly_digest;
    }

    private function resolvedWeekStartsOn(): ?string
    {
        if ($this->has('week_starts_on')) {
            $weekStartsOn = $this->normalizeWeekStartsOn($this->input('week_starts_on'));

            return $weekStartsOn !== '' ? $weekStartsOn : null;
        }

        $digest = $this->route('digest');

        if (! $digest instanceof Digest || ! is_string($digest->week_starts_on)) {
            return null;
        }

        return $this->normalizeWeekStartsOn($digest->week_starts_on);
    }
}
