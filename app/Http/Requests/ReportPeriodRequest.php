<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class ReportPeriodRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate the period selection query params used by financial reports.
     * A date range (date_from/date_to) or a period_id may be supplied; dates
     * must be valid and date_to must not precede date_from.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hasFrom = $this->filled('date_from');
        $hasTo = $this->filled('date_to');

        if ($hasFrom || $hasTo) {
            return [
                'date_from' => ['required', 'date', 'date_format:Y-m-d'],
                'date_to' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            ];
        }

        if ($this->filled('period_id')) {
            return [
                'period_id' => ['required', 'string', 'regex:/^[0-9]+(,[0-9]+)*$/'],
            ];
        }

        return [];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'date_from.required' => 'Please provide a start date (date_from) when filtering reports by date.',
            'date_to.required' => 'Please provide an end date (date_to) when filtering reports by date.',
            'date_from.date_format' => 'The start date must be in YYYY-MM-DD format.',
            'date_to.date_format' => 'The end date must be in YYYY-MM-DD format.',
            'date_to.after_or_equal' => 'The end date must be on or after the start date.',
            'period_id.regex' => 'The period_id must be a single ID or a comma-separated list of IDs.',
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }
}
