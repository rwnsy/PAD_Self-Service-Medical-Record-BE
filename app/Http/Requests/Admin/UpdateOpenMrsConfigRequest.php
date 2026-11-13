<?php

namespace App\Http\Requests\Admin;

use App\Services\OpenMrsUrlValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class UpdateOpenMrsConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint_url' => ['required', 'url', 'max:500'],
            'credential' => ['required', 'array'],
            'credential.username' => ['required', 'string', 'max:255'],
            'credential.password' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $endpointUrl = $this->input('endpoint_url');
            if (is_string($endpointUrl) && $endpointUrl !== '') {
                try {
                    app(OpenMrsUrlValidator::class)->validate($endpointUrl);
                } catch (InvalidArgumentException $e) {
                    $validator->errors()->add('endpoint_url', $e->getMessage());
                }
            }
        });
    }
}
