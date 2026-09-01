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
 * See StoreFiberNodeRequest's own docblock — same rules, adjusted for
 * partial updates. node_type falls back to the route-bound model's own
 * stored value when omitted from the request, same idiom already
 * established by UpdateNetworkProfileGroupRequest (v0.14.3.1).
 */
class UpdateFiberNodeRequest extends FormRequest
{
    /** @var FiberNode */
    private $fiberNode;

    public function authorize(): bool
    {
        return $this->user()->can('network_infrastructure.manage');
    }

    protected function prepareForValidation(): void
    {
        $this->fiberNode = $this->route('fiber_node');
    }

    public function rules(): array
    {
        return [
            'reseller_id' => [
                'nullable', 'integer',
                Rule::exists(Reseller::class, 'id')->where('tenant_id', $this->user()->tenant_id),
            ],
            'node_type' => ['sometimes', 'required', 'string', Rule::in(['otb', 'closure', 'odc'])],
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

        $nodeType = $this->input('node_type', $this->fiberNode?->node_type?->value);
        $target = new FiberNode(['node_type' => $nodeType]);

        if (! app(FiberTopologyService::class)->isLossRequired($target)) {
            return;
        }

        $lossIn = $this->input('loss_in_db', $this->fiberNode?->loss_in_db);
        $lossOut = $this->input('loss_out_db', $this->fiberNode?->loss_out_db);

        if ($lossIn === null || $lossIn === '') {
            $validator->errors()->add('loss_in_db', 'Redaman masuk (loss in) wajib diisi untuk titik ODC.');
        }

        if ($lossOut === null || $lossOut === '') {
            $validator->errors()->add('loss_out_db', 'Redaman keluar (loss out) wajib diisi untuk titik ODC.');
        }
    }
}
