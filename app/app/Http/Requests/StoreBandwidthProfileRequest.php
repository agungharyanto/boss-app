<?php

namespace App\Http\Requests;

use App\Models\BandwidthProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Values here are always Kbps — a unit-picker (Kbps/Mbps) is a Livewire-UI
 * convenience layer only, converting to Kbps before ever reaching this
 * Request or the REST API (see App\Livewire\Network\BandwidthProfileIndex).
 */
class StoreBandwidthProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', BandwidthProfile::class);
    }

    /**
     * Real bug found via manual UI testing: "10Mbps" and "10Mbps " (a
     * trailing space) are byte-distinct strings, so Rule::unique() below
     * never caught them as duplicates — trimming BEFORE validation runs
     * means the uniqueness check compares the same value that will
     * actually end up stored (BandwidthProfile::name()'s own mutator also
     * trims on write, but that alone isn't enough — it runs too late to
     * affect this validation-time query).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                // whereNull('deleted_at') is NOT automatic here —
                // Rule::unique() queries the raw table directly, it does
                // not go through Eloquent/SoftDeletingScope, so a
                // soft-deleted profile's name would otherwise stay
                // permanently blocked despite the partial unique index
                // (WHERE deleted_at IS NULL) allowing reuse at the DB
                // level. Caught for real by BandwidthProfileApiTest.
                Rule::unique(BandwidthProfile::class, 'name')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->whereNull('deleted_at'),
            ],
            'upload_min' => ['required', 'integer', 'min:1'],
            'upload_max' => ['required', 'integer', 'gte:upload_min'],
            'download_min' => ['required', 'integer', 'min:1'],
            'download_max' => ['required', 'integer', 'gte:download_min'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
