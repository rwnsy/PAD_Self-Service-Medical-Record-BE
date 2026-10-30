<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->user_id, 'user_id'),
            ],
            'nik' => ['sometimes', 'digits:16'],
            'gender' => ['sometimes', 'string', Rule::in(['MALE', 'FEMALE'])],
            'date_of_birth' => ['sometimes', 'date', 'after:1900-01-01', 'before:today'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->filled('nik') || $validator->errors()->has('nik')) {
                    return;
                }

                $nikHash = User::hashNik((string) $this->input('nik'));
                $exists = User::query()
                    ->where('nik_hash', $nikHash)
                    ->whereKeyNot($this->user()?->user_id)
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('nik', 'The NIK has already been registered.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $attributes = [];

        if ($this->has('email')) {
            $attributes['email'] = Str::lower((string) $this->input('email'));
        }

        if ($this->has('nik')) {
            $attributes['nik'] = trim((string) $this->input('nik'));
        }

        if ($this->has('gender')) {
            $attributes['gender'] = Str::upper(trim((string) $this->input('gender')));
        }

        if ($attributes !== []) {
            $this->merge($attributes);
        }
    }
}
