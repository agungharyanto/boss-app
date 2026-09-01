<?php

namespace App\Http\Requests;

use App\Models\FiberNode;
use App\Models\Odp;
use App\Models\Reseller;
use App\Services\Network\FiberTopologyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * v0.16.0 — Core Network Infrastructure Management, Langkah 2. No
 * route/Controller wires into this yet (Langkah 3) — `parent_type` is
 * validated against the raw morph class-string this codebase stores in
 * the column (no Relation::morphMap() is configured anywhere in this
 * project, confirmed before writing this), not a short alias — a nicer
 * wire format (e.g. a `parent_kind` discriminator) can be decided once
 * Langkah 3 designs the real HTTP/Livewire contract.
 */
class StoreFiberNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('network_infrastructure.manage');
    }

    public function rules(): array
    {
        return [
            'reseller_id' => [
                'nullable', 'integer',
                Rule::exists(Reseller::class, 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
            'node_type' => ['required', 'string', Rule::in(['otb', 'closure', 'odc'])],
            'local_label' => ['nullable', 'string', 'max:255'],
            'parent_type' => ['nullable', 'string', Rule::in([FiberNode::class, Odp::class])],
            'parent_id' => ['nullable', 'integer', 'required_with:parent_type'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'loss_in_db' => ['nullable', 'numeric'],
            'loss_out_db' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateParentExists($validator);
            $this->validateLossRequired($validator);
        });
    }

    private function validateParentExists(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['parent_type', 'parent_id'])) {
            return;
        }

        $parentType = $this->input('parent_type');
        $parentId = $this->input('parent_id');

        if ($parentType === null || $parentId === null) {
            return;
        }

        // Plain scoped find() — the tenant scope on both FiberNode/Odp
        // already restricts this to a row the caller's own tenant owns.
        $exists = $parentType === FiberNode::class
            ? FiberNode::find($parentId) !== null
            : Odp::find($parentId) !== null;

        if (! $exists) {
            $validator->errors()->add('parent_id', 'Titik induk (parent) yang dipilih tidak ditemukan.');
        }
    }

    private function validateLossRequired(Validator $validator): void
    {
        if ($validator->errors()->has('node_type')) {
            return;
        }

        $target = new FiberNode(['node_type' => $this->input('node_type')]);

        if (! app(FiberTopologyService::class)->isLossRequired($target)) {
            return;
        }

        if ($this->input('loss_in_db') === null || $this->input('loss_in_db') === '') {
            $validator->errors()->add('loss_in_db', 'Redaman masuk (loss in) wajib diisi untuk titik ODC.');
        }

        if ($this->input('loss_out_db') === null || $this->input('loss_out_db') === '') {
            $validator->errors()->add('loss_out_db', 'Redaman keluar (loss out) wajib diisi untuk titik ODC.');
        }
    }
}
