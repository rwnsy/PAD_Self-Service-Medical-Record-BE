<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateHealthRecordRequest extends FormRequest
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
            'height' => ['sometimes', 'numeric', 'between:50,300'],
            'weight' => ['sometimes', 'numeric', 'between:2,500'],
            'systolic' => ['sometimes', 'integer', 'between:50,300'],
            'diastolic' => ['sometimes', 'integer', 'between:30,200'],
            'recorded_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $healthRecord = $this->user()?->healthRecords()
                    ->whereKey($this->route('healthRecordId'))
                    ->first();

                if ($healthRecord === null) {
                    return;
                }

                $height = $this->has('height') ? $this->input('height') : $healthRecord->height;
                $weight = $this->has('weight') ? $this->input('weight') : $healthRecord->weight;
                $systolic = $this->has('systolic') ? $this->input('systolic') : $healthRecord->systolic;
                $diastolic = $this->has('diastolic') ? $this->input('diastolic') : $healthRecord->diastolic;

                if (($height === null) !== ($weight === null)) {
                    $validator->errors()->add('height', 'Height and weight must be provided together.');
                }

                if (($systolic === null) !== ($diastolic === null)) {
                    $validator->errors()->add('systolic', 'Systolic and diastolic pressure must be provided together.');
                }

                if ($validator->errors()->hasAny(['systolic', 'diastolic'])) {
                    return;
                }

                if ($systolic !== null && $diastolic !== null && (int) $systolic <= (int) $diastolic) {
                    $validator->errors()->add('systolic', 'The systolic pressure must be greater than the diastolic pressure.');
                }
            },
        ];
    }
}
