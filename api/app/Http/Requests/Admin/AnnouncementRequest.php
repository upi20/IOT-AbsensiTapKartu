<?php

namespace App\Http\Requests\Admin;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $description = is_string($this->input('description')) ? trim($this->input('description')) : $this->input('description');

        $this->merge([
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'description' => $description === '' ? null : $description,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.Announcement::TITLE_MAX],
            'description' => ['nullable', 'string', 'max:'.Announcement::DESCRIPTION_MAX],
            'icon' => ['required', 'string', Rule::in(array_keys(Announcement::ICONS))],
            'is_active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:'.Announcement::SORT_ORDER_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'icon.in' => 'Pilih ikon dari daftar.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'judul',
            'description' => 'deskripsi',
            'icon' => 'ikon',
            'sort_order' => 'urutan',
        ];
    }
}
