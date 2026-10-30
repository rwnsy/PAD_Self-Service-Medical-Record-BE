<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexPersonalMedicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'visit_date_from' => ['nullable', 'date'],
            'visit_date_to' => ['nullable', 'date', 'after_or_equal:visit_date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}

