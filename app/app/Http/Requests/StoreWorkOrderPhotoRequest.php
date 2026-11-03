<?php

namespace App\Http\Requests;

use App\Enums\WorkOrderPhotoType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkOrderPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', $this->route('work_order'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(WorkOrderPhotoType::class)],
            // v0.13.4.3.1 hardening: `image` saja mengizinkan SVG (vektor XSS);
            // batasi eksplisit ke raster. v0.13.4.3.2: WebP di-drop (gagal di
            // whitelist media WA grup "WO selesai") → hanya jpg/jpeg/png.
            // `mimes` memeriksa ekstensi tebakan dari ISI file (MIME).
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Foto harus JPG atau PNG (WebP tidak didukung).',
            'file.max' => 'Foto maksimal 10 MB.',
            'file.image' => 'File harus berupa gambar JPG/PNG.',
        ];
    }
}
