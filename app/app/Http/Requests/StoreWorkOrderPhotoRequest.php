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
            // batasi eksplisit ke raster jpg/jpeg/png/webp. `mimes` memeriksa
            // ekstensi tebakan dari ISI file (MIME), bukan nama asli client.
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }
}
