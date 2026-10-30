<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
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
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', Rule::when($this->has('password_confirmation'), ['confirmed'])],
            'phone' => ['nullable', 'string', 'max:25'],
            'nik' => [
                'nullable',
                'digits:16',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (User::query()->where('nik_hash', User::hashNik((string) $value))->exists()) {
                        $fail('The NIK has already been registered.');
                    }
                },
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => Str::lower((string) $this->input('email')),
            ]);
        }

        if ($this->has('nik')) {
            $this->merge(['nik' => trim((string) $this->input('nik'))]);
        }
    }
}
