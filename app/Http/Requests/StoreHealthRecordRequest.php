<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreHealthRecordRequest extends FormRequest
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
            'height' => ['nullable', 'numeric', 'between:50,300'],
            'weight' => ['nullable', 'numeric', 'between:2,500'],
            'systolic' => ['nullable', 'integer', 'between:50,300'],
            'diastolic' => ['nullable', 'integer', 'between:30,200'],
            'recorded_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hasHeight = $this->filled('height');
                $hasWeight = $this->filled('weight');
                $hasSystolic = $this->filled('systolic');
                $hasDiastolic = $this->filled('diastolic');

                if (! $hasHeight && ! $hasWeight && ! $hasSystolic && ! $hasDiastolic) {
                    $validator->errors()->add('measurements', 'Provide either height and weight, or systolic and diastolic pressure.');
                }

                if ($hasHeight !== $hasWeight) {
                    $validator->errors()->add('height', 'Height and weight must be provided together.');
                }

                if ($hasSystolic !== $hasDiastolic) {
                    $validator->errors()->add('systolic', 'Systolic and diastolic pressure must be provided together.');
                }

                if ($validator->errors()->hasAny(['systolic', 'diastolic'])) {
                    return;
                }

                if ($hasSystolic && $this->integer('systolic') <= $this->integer('diastolic')) {
                    $validator->errors()->add('systolic', 'The systolic pressure must be greater than the diastolic pressure.');
                }
            },
        ];
    }
}
