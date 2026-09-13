<?php

namespace App\Livewire\Network;

use App\Models\ModemType;
use App\Models\WanConfigTemplate;
use App\Services\Network\ModemTypeService;
use App\Services\Network\WanConfigTemplateService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * v0.12.5 (revisi arsitektur) — "Template Konfig CPE" TIDAK terikat Paket
 * sama sekali, dibedakan HANYA oleh Tipe Modem. Tampilan dikelompokkan
 * per Tipe Modem (+ grup "Generic/Tanpa Tipe Modem" untuk
 * `modem_type_id` NULL). Assignment ke device terjadi di Detail
 * Perangkat CPE (auto-suggest atau manual), bukan di halaman ini —
 * halaman ini murni katalog Template + katalog Tipe Modem.
 *
 * BELUM ada sync ke GenieACS di sini — WanConfigTemplateService tidak
 * dispatch job apa pun (GenieAcsPresetService per-template adalah bagian
 * TERPISAH, v0.12.6).
 */
class WanConfigTemplateIndex extends Component
{
    use AuthorizesRequests;

    // --- Form Template (modal) ---
    public bool $showTemplateForm = false;

    public ?int $editingTemplateId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    /** '' = belum dipilih (invalid). 'generic' = modem_type_id NULL. Selain itu = id ModemType. */
    #[Validate('required|string')]
    public string $modemTypeSelection = '';

    public bool $enabled = false;

    public bool $wan1Enabled = true;

    #[Validate('required|integer|min:1|max:4094')]
    public string $wan1Vlan = '1000';

    #[Validate('required|string|min:1|max:64')]
    public string $wan1PppoeUsername = 'default';

    #[Validate('required|string|min:1|max:64')]
    public string $wan1PppoePassword = 'default';

    public bool $wan2Enabled = false;

    #[Validate('required|integer|min:1|max:4094')]
    public string $wan2Vlan = '1200';

    // --- Modal CRUD ModemType ---
    public bool $showModemTypeModal = false;

    public ?int $editingModemTypeId = null;

    #[Validate('required|string|max:255')]
    public string $modemTypeName = '';

    /** Comma-separated kode OUI, mis. "ZICG,CIOT" — dasar auto-suggest. */
    public string $manufacturerMatchPatterns = '';

    public bool $modemTypeIsActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', WanConfigTemplate::class);
    }

    // ================= Template Konfig CPE =================

    public function openCreateForModemType(?int $modemTypeId = null): void
    {
        $this->authorize('manage', WanConfigTemplate::class);

        $this->resetTemplateForm();
        $this->modemTypeSelection = $modemTypeId !== null ? (string) $modemTypeId : '';
        $this->showTemplateForm = true;
    }

    public function editTemplate(int $templateId): void
    {
        $template = WanConfigTemplate::findOrFail($templateId);
        $this->authorize('manage', WanConfigTemplate::class);

        $this->editingTemplateId = $template->id;
        $this->name = $template->name;
        $this->modemTypeSelection = $template->modem_type_id === null ? 'generic' : (string) $template->modem_type_id;
        $this->enabled = $template->enabled;
        $this->wan1Enabled = $template->wan1_enabled;
        $this->wan1Vlan = (string) $template->wan1_vlan;
        $this->wan1PppoeUsername = $template->wan1_pppoe_username;
        $this->wan1PppoePassword = $template->wan1_pppoe_password;
        $this->wan2Enabled = $template->wan2_enabled;
        $this->wan2Vlan = (string) $template->wan2_vlan;
        $this->showTemplateForm = true;
    }

    public function closeTemplateForm(): void
    {
        $this->resetTemplateForm();
        $this->showTemplateForm = false;
    }

    private function resetTemplateForm(): void
    {
        $this->reset([
            'editingTemplateId', 'name', 'modemTypeSelection', 'enabled',
            'wan1Enabled', 'wan1Vlan', 'wan1PppoeUsername', 'wan1PppoePassword',
            'wan2Enabled', 'wan2Vlan',
        ]);
        $this->wan1Enabled = true;
        $this->wan1Vlan = '1000';
        $this->wan1PppoeUsername = 'default';
        $this->wan1PppoePassword = 'default';
        $this->wan2Vlan = '1200';
        $this->resetErrorBag();
    }

    public function saveTemplate(WanConfigTemplateService $service): void
    {
        $this->authorize('manage', WanConfigTemplate::class);

        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'modemTypeSelection' => ['required', 'string'],
            'wan1Vlan' => ['required', 'integer', 'min:1', 'max:4094'],
            'wan1PppoeUsername' => ['required', 'string', 'min:1', 'max:64'],
            'wan1PppoePassword' => ['required', 'string', 'min:1', 'max:64'],
            'wan2Vlan' => ['required', 'integer', 'min:1', 'max:4094'],
        ]);

        if ($this->modemTypeSelection !== 'generic') {
            $this->validate([
                'modemTypeSelection' => [
                    Rule::exists(ModemType::class, 'id')->whereNull('deleted_at'),
                ],
            ]);
        }

        $modemTypeId = $this->modemTypeSelection === 'generic' ? null : (int) $this->modemTypeSelection;

        $data = [
            'name' => $validated['name'],
            'modem_type_id' => $modemTypeId,
            'enabled' => $this->enabled,
            'wan1_enabled' => $this->wan1Enabled,
            'wan1_vlan' => (int) $validated['wan1Vlan'],
            'wan1_pppoe_username' => $validated['wan1PppoeUsername'],
            'wan1_pppoe_password' => $validated['wan1PppoePassword'],
            'wan2_enabled' => $this->wan2Enabled,
            'wan2_vlan' => (int) $validated['wan2Vlan'],
        ];

        try {
            if ($this->editingTemplateId !== null) {
                $service->update(WanConfigTemplate::findOrFail($this->editingTemplateId), $data);
            } else {
                $service->create($data);
            }
        } catch (InvalidArgumentException $e) {
            $this->addError('modemTypeSelection', $e->getMessage());

            return;
        }

        $this->closeTemplateForm();
    }

    public function deleteTemplate(int $templateId, WanConfigTemplateService $service): void
    {
        $this->authorize('manage', WanConfigTemplate::class);

        $service->delete(WanConfigTemplate::findOrFail($templateId));
    }

    // ================= CRUD Tipe Modem (modal) =================

    public function openModemTypeModal(): void
    {
        $this->authorize('manage', WanConfigTemplate::class);

        $this->showModemTypeModal = true;
    }

    public function closeModemTypeModal(): void
    {
        $this->resetModemTypeForm();
        $this->showModemTypeModal = false;
    }

    private function resetModemTypeForm(): void
    {
        $this->reset(['editingModemTypeId', 'modemTypeName', 'manufacturerMatchPatterns']);
        $this->modemTypeIsActive = true;
        $this->resetErrorBag();
    }

    public function editModemType(int $modemTypeId): void
    {
        $modemType = ModemType::findOrFail($modemTypeId);
        $this->authorize('manage', WanConfigTemplate::class);

        $this->editingModemTypeId = $modemType->id;
        $this->modemTypeName = $modemType->name;
        $this->manufacturerMatchPatterns = (string) $modemType->manufacturer_match_patterns;
        $this->modemTypeIsActive = $modemType->is_active;
    }

    public function cancelEditModemType(): void
    {
        $this->resetModemTypeForm();
    }

    public function saveModemType(ModemTypeService $service): void
    {
        $this->authorize('manage', WanConfigTemplate::class);

        $this->modemTypeName = trim($this->modemTypeName);

        $validated = $this->validate([
            'modemTypeName' => [
                'required', 'string', 'max:255',
                Rule::unique(ModemType::class, 'name')
                    ->whereNull('deleted_at')
                    ->when($this->editingModemTypeId, fn ($rule) => $rule->ignore($this->editingModemTypeId)),
            ],
        ]);

        $data = [
            'name' => $validated['modemTypeName'],
            'manufacturer_match_patterns' => $this->manufacturerMatchPatterns !== '' ? $this->manufacturerMatchPatterns : null,
            'is_active' => $this->modemTypeIsActive,
        ];

        if ($this->editingModemTypeId !== null) {
            $service->update(ModemType::findOrFail($this->editingModemTypeId), $data);
        } else {
            $service->create($data);
        }

        $this->resetModemTypeForm();
    }

    public function deleteModemType(int $modemTypeId, ModemTypeService $service): void
    {
        $this->authorize('manage', WanConfigTemplate::class);

        try {
            $service->delete(ModemType::findOrFail($modemTypeId));
        } catch (InvalidArgumentException $e) {
            $this->addError('modemTypeDelete', $e->getMessage());
        }
    }

    public function render()
    {
        $modemTypes = ModemType::query()->orderBy('name')->get();
        $genericTemplates = WanConfigTemplate::query()->whereNull('modem_type_id')->orderBy('name')->get();

        return view('livewire.network.wan-config-template-index', [
            'modemTypes' => $modemTypes,
            'genericTemplates' => $genericTemplates,
            'templatesByModemType' => WanConfigTemplate::query()
                ->whereNotNull('modem_type_id')
                ->orderBy('name')
                ->get()
                ->groupBy('modem_type_id'),
            'canManage' => auth()->user()->can('manage', WanConfigTemplate::class),
        ])->layout('layouts.app');
    }
}
