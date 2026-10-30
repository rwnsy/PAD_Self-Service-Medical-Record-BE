<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonalMedicalRecordRequest extends FormRequest
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
            'visit_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'facility_name' => ['sometimes', 'required', 'string', 'max:255'],
            'complaint' => ['sometimes', 'required', 'string'],
            'medications' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'visit_date.required' => 'Tanggal pemeriksaan wajib diisi.',
            'visit_date.date' => 'Format tanggal pemeriksaan tidak valid.',
            'visit_date.before_or_equal' => 'Tanggal pemeriksaan tidak boleh melebihi hari ini.',
            'facility_name.required' => 'Nama fasilitas atau tempat pemeriksaan wajib diisi.',
            'complaint.required' => 'Keluhan pemeriksaan wajib diisi.',
        ];
    }
}

