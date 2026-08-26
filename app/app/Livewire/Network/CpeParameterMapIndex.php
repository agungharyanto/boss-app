<?php

namespace App\Livewire\Network;

use App\Enums\CpeParameterConversionFormula;
use App\Models\CpeParameterMap;
use App\Services\Network\CpeParameterResolverService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Pengaturan > Mapping Parameter CPE" (v0.7.2) — superadmin-only, no
 * reseller branching at all (platform-level catalog, same posture as
 * PaymentGatewaySettings). Includes a "Test Resolve" panel against a real
 * GenieACS device id — the actual proof a mapping works, not just that a
 * row was saved correctly.
 */
class CpeParameterMapIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $oui = '';

    public string $productClass = '';

    public string $parameterKey = '';

    public string $parameterPath = '';

    public string $valueType = '';

    public string $conversionFormula = 'raw';

    public string $conversionParamsJson = '{}';

    public string $notes = '';

    public string $resolveDeviceId = '';

    /** @var array<string, array{parameter_key: string, parameter_path: string, raw_value: mixed, value: ?float, verified: bool, error: ?string}>|null */
    public ?array $resolveResult = null;

    public function mount(): void
    {
        $this->authorize('viewAny', CpeParameterMap::class);
    }

    /**
     * @return array<int, string>
     */
    public function conversionFormulaOptions(): array
    {
        return array_map(fn (CpeParameterConversionFormula $f) => $f->value, CpeParameterConversionFormula::cases());
    }

    public function create(): void
    {
        $this->authorize('manage', CpeParameterMap::class);

        $this->reset([
            'editingId', 'oui', 'productClass', 'parameterKey', 'parameterPath',
            'valueType', 'notes',
        ]);
        $this->conversionFormula = CpeParameterConversionFormula::Raw->value;
        $this->conversionParamsJson = '{}';
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('manage', CpeParameterMap::class);

        $map = CpeParameterMap::findOrFail($id);

        $this->editingId = $map->id;
        $this->oui = $map->oui;
        $this->productClass = $map->product_class;
        $this->parameterKey = $map->parameter_key;
        $this->parameterPath = $map->parameter_path;
        $this->valueType = (string) $map->value_type;
        $this->conversionFormula = $map->conversion_formula->value;
        $this->conversionParamsJson = json_encode($map->conversion_params ?? new \stdClass, JSON_PRETTY_PRINT);
        $this->notes = (string) $map->notes;
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->editingId = null;
    }

    public function save(): void
    {
        $this->authorize('manage', CpeParameterMap::class);

        $conversionParams = json_decode($this->conversionParamsJson, true);

        $this->validate([
            'oui' => ['required', 'string', 'max:32'],
            'productClass' => ['required', 'string', 'max:255'],
            'parameterKey' => ['required', 'string', 'max:255'],
            'parameterPath' => ['required', 'string', 'max:1000'],
            'conversionFormula' => ['required'],
        ]);

        if ($conversionParams === null && trim($this->conversionParamsJson) !== '' && trim($this->conversionParamsJson) !== '{}') {
            $this->addError('conversionParamsJson', 'JSON tidak valid.');

            return;
        }

        $data = [
            'oui' => $this->oui,
            'product_class' => $this->productClass,
            'parameter_key' => $this->parameterKey,
            'parameter_path' => $this->parameterPath,
            'value_type' => $this->valueType ?: null,
            'conversion_formula' => $this->conversionFormula,
            'conversion_params' => $conversionParams ?: null,
            'notes' => $this->notes ?: null,
        ];

        if ($this->editingId) {
            $map = CpeParameterMap::findOrFail($this->editingId);
            // Editing the definition demotes an already-verified row — see
            // UpdateCpeParameterMapRequest's docblock for the same rule
            // enforced on the API side.
            $map->fill($data);
            $map->verified_at = null;
            $map->verified_against_device_id = null;
            $map->save();
        } else {
            CpeParameterMap::create($data);
        }

        $this->showForm = false;
        $this->editingId = null;
    }

    public function delete(int $id): void
    {
        $this->authorize('manage', CpeParameterMap::class);

        CpeParameterMap::findOrFail($id)->delete();
    }

    public function resolve(CpeParameterResolverService $resolver): void
    {
        $this->authorize('view', CpeParameterMap::class);

        $this->validate(['resolveDeviceId' => ['required', 'string']]);

        $this->resolveResult = $resolver->resolveForDevice($this->resolveDeviceId);
    }

    public function markVerified(string $parameterKeyToVerify): void
    {
        $this->authorize('manage', CpeParameterMap::class);

        if (! $this->resolveDeviceId || ! isset($this->resolveResult[$parameterKeyToVerify])) {
            return;
        }

        $result = $this->resolveResult[$parameterKeyToVerify];

        if ($result['error'] !== null) {
            return;
        }

        CpeParameterMap::query()
            ->where('parameter_key', $parameterKeyToVerify)
            ->where('parameter_path', $result['parameter_path'])
            ->update([
                'verified_at' => now(),
                'verified_against_device_id' => $this->resolveDeviceId,
            ]);
    }

    public function render()
    {
        return view('livewire.network.cpe-parameter-map-index', [
            'maps' => CpeParameterMap::query()
                ->orderBy('oui')
                ->orderBy('product_class')
                ->orderBy('parameter_key')
                ->paginate(15),
        ]);
    }
}
