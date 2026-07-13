<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ModuleAccessService;
use Illuminate\Validation\Rule;

class AttachUserRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => ['required', 'integer'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in(ModuleAccessService::assignableModuleSlugs())],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'link_employee_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Enter an email address.',
            'role_id.required' => 'Select a role before attaching this person.',
        ];
    }
}
