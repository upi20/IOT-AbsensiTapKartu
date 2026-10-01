<?php

namespace App\Http\Requests\Admin;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $identifier = is_string($this->input('identifier')) ? trim($this->input('identifier')) : $this->input('identifier');

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'identifier' => $identifier === '' ? null : $identifier,
            'card_uid' => is_string($this->input('card_uid')) ? Member::normalizeUid($this->input('card_uid')) : $this->input('card_uid'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $member = $this->route('member');

        return [
            'name' => ['required', 'string', 'min:'.Member::NAME_MIN, 'max:'.Member::NAME_MAX],
            'identifier' => ['nullable', 'string', 'max:'.Member::IDENTIFIER_MAX, 'regex:'.Member::IDENTIFIER_PATTERN,
                Rule::unique('members', 'identifier')->ignore($member)],
            'card_uid' => ['required', 'string', 'regex:/^[0-9A-F]{8,20}$/',
                Rule::unique('members', 'card_uid')->ignore($member)],
            'is_active' => ['boolean'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
            'remove_photo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Data kolom anggota (tanpa foto; foto diproses terpisah oleh MemberPhoto).
     *
     * @return array<string, mixed>
     */
    public function memberData(): array
    {
        return $this->safe()->only(['name', 'identifier', 'card_uid', 'is_active']);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identifier.regex' => 'NIS/NIP hanya boleh berisi huruf, angka, titik, strip, dan garis miring.',
            'identifier.unique' => 'NIS/NIP sudah dipakai anggota lain.',
            'card_uid.regex' => 'Nomor kartu tidak valid. Gunakan 10 digit angka (mis. 0218893066) atau 8–20 karakter hex.',
            'card_uid.unique' => 'Kartu ini sudah terdaftar atas nama anggota lain.',
            'photo.mimes' => 'Foto harus berupa JPG atau PNG.',
            'photo.max' => 'Ukuran foto maksimal 8 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'identifier' => 'NIS/NIP',
            'card_uid' => 'nomor kartu',
            'photo' => 'foto',
        ];
    }
}
