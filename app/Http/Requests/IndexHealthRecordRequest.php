<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexHealthRecordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'recorded_from' => ['nullable', 'date'],
            'recorded_to' => ['nullable', 'date', 'after_or_equal:recorded_from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'warning_status' => ['nullable', 'string', 'in:NORMAL,ATTENTION,URGENT'],
        ];
    }
}
