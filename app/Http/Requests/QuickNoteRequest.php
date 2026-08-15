<?php

namespace App\Http\Requests;

class QuickNoteRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'title' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:20'],
            'tag' => ['nullable', 'string', 'max:60'],
            'is_shared' => ['nullable', 'boolean'],
            'is_pinned' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'client_uuid' => ['nullable', 'string', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'title.required' => 'Please enter a note title.',
            'title.string' => 'The note title must be text.',
            'title.max' => 'The note title must not exceed 255 characters.',
            'tag.max' => 'The note tag must not exceed 60 characters.',
            'color.max' => 'The note color must not exceed 20 characters.',
            'client_uuid.uuid' => 'The note identifier is invalid.',
        ]);
    }
}
