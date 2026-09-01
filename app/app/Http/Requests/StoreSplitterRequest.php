<?php

namespace App\Http\Requests;

use App\Models\FiberNode;
use App\Models\Odp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSplitterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('network_infrastructure.manage');
    }

    public function rules(): array
    {
        return [
            'owner_type' => ['required', 'string', Rule::in([FiberNode::class, Odp::class])],
            'owner_id' => ['required', 'integer'],
            'ratio' => ['required', 'string', 'max:20'],
            'model' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateOwnerExists($validator);
        });
    }

    private function validateOwnerExists(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['owner_type', 'owner_id'])) {
            return;
        }

        $type = $this->input('owner_type');
        $id = $this->input('owner_id');

        $exists = $type === FiberNode::class
            ? FiberNode::find($id) !== null
            : Odp::find($id) !== null;

        if (! $exists) {
            $validator->errors()->add('owner_id', 'Titik pemilik splitter yang dipilih tidak ditemukan.');
        }
    }
}
