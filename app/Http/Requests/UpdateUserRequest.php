<?php

namespace App\Http\Requests;

use App\Services\ModuleAccessService;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['nullable', 'string', 'min:6'],
            'role_id' => ['nullable', 'integer'],
            // Org membership uses detach/attach — business actors cannot toggle is_active.
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', Rule::in(ModuleAccessService::assignableModuleSlugs())],
        ];
    }

    public function messages(): array
    {
        return [
            'role_id.integer' => 'Select a valid staff role.',
        ];
    }
}
