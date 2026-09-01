<?php

namespace App\Http\Requests;

use App\Models\FiberNode;
use App\Models\Odp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * v0.16.0 — Core Network Infrastructure Management, Langkah 2. The
 * even-core / tube×core-per-tube-matches-total checks here are a
 * FormRequest-level pre-check so a caller gets a clean 422 with an
 * Indonesian message rather than an exception bubbling up from
 * FiberTopologyService::createCable() (which independently re-checks the
 * exact same conditions — defense in depth, same posture as this
 * codebase's other cross-entity checks that live in both layers).
 */
class StoreFiberCableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('network_infrastructure.manage');
    }

    public function rules(): array
    {
        return [
            'from_type' => ['required', 'string', Rule::in([FiberNode::class, Odp::class])],
            'from_id' => ['required', 'integer'],
            'to_type' => ['required', 'string', Rule::in([FiberNode::class, Odp::class])],
            'to_id' => ['required', 'integer'],
            'total_cores' => ['required', 'integer', 'min:2'],
            'tube_count' => ['required', 'integer', 'min:1'],
            'cores_per_tube' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateEndpointsExist($validator);
            $this->validateEndpointsDiffer($validator);
            $this->validateCoreCounts($validator);
        });
    }

    private function validateEndpointsExist(Validator $validator): void
    {
        foreach (['from', 'to'] as $side) {
            if ($validator->errors()->hasAny(["{$side}_type", "{$side}_id"])) {
                continue;
            }

            $type = $this->input("{$side}_type");
            $id = $this->input("{$side}_id");

            $exists = $type === FiberNode::class
                ? FiberNode::find($id) !== null
                : Odp::find($id) !== null;

            if (! $exists) {
                $validator->errors()->add("{$side}_id", 'Titik yang dipilih tidak ditemukan.');
            }
        }
    }

    private function validateEndpointsDiffer(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['from_type', 'from_id', 'to_type', 'to_id'])) {
            return;
        }

        if ($this->input('from_type') === $this->input('to_type') && (int) $this->input('from_id') === (int) $this->input('to_id')) {
            $validator->errors()->add('to_id', 'Titik awal dan titik akhir kabel tidak boleh sama.');
        }
    }

    private function validateCoreCounts(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['total_cores', 'tube_count', 'cores_per_tube'])) {
            return;
        }

        $totalCores = (int) $this->input('total_cores');
        $tubeTimesCore = (int) $this->input('tube_count') * (int) $this->input('cores_per_tube');

        if ($totalCores % 2 !== 0) {
            $validator->errors()->add('total_cores', 'Jumlah core harus genap.');

            return;
        }

        if ($tubeTimesCore % 2 !== 0) {
            $validator->errors()->add('cores_per_tube', 'Jumlah tube dikali core per tube harus genap juga.');

            return;
        }

        if ($tubeTimesCore !== $totalCores) {
            $validator->errors()->add('cores_per_tube', 'Jumlah tube dikali core per tube harus sama dengan jumlah core total.');
        }
    }
}
