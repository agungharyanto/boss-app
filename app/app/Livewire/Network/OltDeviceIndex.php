<?php

namespace App\Livewire\Network;

use App\Enums\OltAccessProtocol;
use App\Enums\OltConnectionTestResult;
use App\Enums\OltPonType;
use App\Models\Nas;
use App\Models\OltDevice;
use App\Models\OltManufacturer;
use App\Models\OltModel;
use App\Models\Reseller;
use App\Services\Network\OltDeviceService;
use App\Support\ResellerContext;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * v0.8.1 OLT Credential Registry — list is DataTables-driven (see
 * OltDeviceDatatableController + the paired Blade view), but unlike
 * CpeDeviceIndex's own v0.7.6-follow-up rewrite, the create/edit FORM stays
 * a genuinely reactive Livewire form (not fetch()-driven modals) — the
 * manufacturer→model cascade and per-protocol dynamic fields need
 * server-side conditional rendering on every change, which Livewire's own
 * reactivity model fits more naturally than hand-rolled JS state.
 *
 * Test Connection is DELIBERATELY session-scoped, not just
 * "has this row ever been tested" — testPassedForKey tracks the EXACT
 * (nas_id, ip_address) pair the last successful test matched. Changing
 * either field after a pass invalidates it (see updated()), so save()
 * can never persist a row whose CURRENTLY TYPED nas/IP combo was never
 * actually verified reachable.
 *
 * SNMP is NOT one of the accessProtocol choices (addendum #2) — it's a
 * monitoring protocol independent of CLI admin access (Telnet/SSH), so
 * its fields are always-on, never conditional on accessProtocol. See
 * App\Enums\OltAccessProtocol's own docblock for why this was fixed
 * after being modeled wrong the first time.
 */
class OltDeviceIndex extends Component
{
    use AuthorizesRequests;

    public bool $showForm = false;

    public ?int $editingOltDeviceId = null;

    public string $name = '';

    public ?int $nasId = null;

    public string $ipAddress = '';

    public ?int $oltManufacturerId = null;

    public ?int $oltModelId = null;

    public string $accessProtocol = '';

    public int $telnetPort = 2333;

    public string $telnetUsername = '';

    public string $telnetPassword = '';

    public int $sshPort = 22;

    public string $sshUsername = '';

    public string $sshPassword = '';

    public string $snmpVersion = 'v2c';

    public int $snmpPort = 2161;

    public string $snmpRoCommunity = '';

    public string $snmpRwCommunity = '';

    public string $notes = '';

    public ?int $resellerId = null;

    /** @var array{result: string, message: string}|null */
    public ?array $testConnectionResult = null;

    /**
     * "{nas_id}|{ip_address}" of the last combo that actually passed —
     * null means "not tested (or invalidated) in this form session".
     */
    public ?string $testPassedForKey = null;

    public bool $showManufacturerModal = false;

    public string $newManufacturerName = '';

    public ?string $manufacturerDeleteError = null;

    public bool $showModelModal = false;

    public string $newModelName = '';

    public string $newModelPonType = '';

    public ?string $modelDeleteError = null;

    public function mount(): void
    {
        $this->authorize('viewAny', OltDevice::class);
    }

    public function isAdmin(): bool
    {
        return auth()->user()->can('olt_devices.manage') || auth()->user()->can('olt_devices.view');
    }

    /**
     * @return Collection<int, OltManufacturer>
     */
    public function manufacturers(): Collection
    {
        return OltManufacturer::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, OltModel>
     */
    public function availableModels(): Collection
    {
        if ($this->oltManufacturerId === null) {
            return collect();
        }

        return OltModel::query()
            ->where('olt_manufacturer_id', $this->oltManufacturerId)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Nas>
     */
    public function nasOptions(): Collection
    {
        return Nas::query()->orderBy('name')->get();
    }

    public function ponTypeOptions(): array
    {
        return OltPonType::cases();
    }

    public function protocolOptions(): array
    {
        return OltAccessProtocol::cases();
    }

    /**
     * Changing either half of the tested (nas_id, ip_address) pair
     * invalidates the previous Test Connection result — the whole point
     * of testPassedForKey is that it must match what's CURRENTLY typed,
     * never a stale combo.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['nasId', 'ipAddress'], true)) {
            $this->testConnectionResult = null;
        }

        if ($property === 'oltManufacturerId') {
            $this->oltModelId = null;
        }
    }

    public function create(): void
    {
        $context = app(ResellerContext::class);

        $this->authorize('create', [OltDevice::class, $context->hasReseller() ? $context->reseller() : null]);

        $this->resetForm();
        $this->editingOltDeviceId = null;

        // Pre-fill both community strings with a random secure value on a
        // brand-new device (never on edit — see edit() below, which
        // deliberately leaves them blank so a blank submit keeps whatever
        // is already saved, same masked-secret convention as telnet/ssh
        // passwords). Still fully editable — a technician who already
        // configured a specific community string manually on the OLT
        // itself can just type over this.
        $this->snmpRoCommunity = Str::password(16);
        $this->snmpRwCommunity = Str::password(16);

        $this->showForm = true;
    }

    public function regenerateSnmpRoCommunity(): void
    {
        $this->snmpRoCommunity = Str::password(16);
    }

    public function regenerateSnmpRwCommunity(): void
    {
        $this->snmpRwCommunity = Str::password(16);
    }

    #[On('editOltDevice')]
    public function edit(int $oltDeviceId): void
    {
        $oltDevice = OltDevice::findOrFail($oltDeviceId);
        $this->authorize('manage', $oltDevice);

        $this->editingOltDeviceId = $oltDevice->id;
        $this->name = $oltDevice->name;
        $this->nasId = $oltDevice->nas_id;
        $this->ipAddress = $oltDevice->ip_address;
        $oltDevice->loadMissing('oltModel');
        $this->oltModelId = $oltDevice->olt_model_id;
        $this->oltManufacturerId = $oltDevice->oltModel->olt_manufacturer_id;
        $this->accessProtocol = $oltDevice->access_protocol->value;
        $this->telnetPort = $oltDevice->telnet_port ?? 2333;
        $this->telnetUsername = (string) $oltDevice->telnet_username;
        $this->telnetPassword = '';
        $this->sshPort = $oltDevice->ssh_port ?? 22;
        $this->sshUsername = (string) $oltDevice->ssh_username;
        $this->sshPassword = '';
        $this->snmpVersion = (string) ($oltDevice->snmp_version ?: 'v2c');
        $this->snmpPort = $oltDevice->snmp_port ?? 2161;
        $this->snmpRoCommunity = '';
        $this->snmpRwCommunity = '';
        $this->notes = (string) $oltDevice->notes;
        $this->resellerId = $oltDevice->reseller_id;

        // A previously-saved row already has a nas_id/ip_address that was
        // presumably tested before it was ever saved (save() enforces
        // this) — so editing it starts already "passed" for its own
        // current combo, without re-running a real network test just to
        // open the edit form. Changing nas_id/ip_address from here still
        // invalidates it via updated() like any other edit. Only trusted
        // when a REAL recorded result exists AND it was a success — a row
        // with no test on record (or a recorded failure) still forces a
        // fresh Test Connection before save() will accept it, same as a
        // brand-new row. This also defends against a row that somehow
        // exists without ever going through save()'s own gate (e.g.
        // seeded/imported directly).
        if ($oltDevice->last_connection_test_result?->value === 'success') {
            $this->testPassedForKey = "{$this->nasId}|{$this->ipAddress}";
            $this->testConnectionResult = ['result' => 'success', 'message' => (string) $oltDevice->last_connection_test_message];
        } else {
            $this->testPassedForKey = null;
            $this->testConnectionResult = $oltDevice->last_connection_test_result !== null
                ? ['result' => $oltDevice->last_connection_test_result->value, 'message' => (string) $oltDevice->last_connection_test_message]
                : null;
        }

        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function testConnection(OltDeviceService $service): void
    {
        $this->validate([
            'nasId' => ['required', 'exists:nas,id'],
            'ipAddress' => ['required', 'ip'],
        ]);

        $nas = Nas::findOrFail($this->nasId);
        $this->authorize('view', $nas);

        $outcome = $service->testConnection($nas, $this->ipAddress);
        $this->testConnectionResult = ['result' => $outcome['result']->value, 'message' => $outcome['message']];

        if ($outcome['result']->value === 'success') {
            $this->testPassedForKey = "{$this->nasId}|{$this->ipAddress}";
        }
    }

    public function openManufacturerModal(): void
    {
        $this->manufacturerDeleteError = null;
        $this->newManufacturerName = '';
        $this->showManufacturerModal = true;
    }

    public function openModelModal(): void
    {
        $this->modelDeleteError = null;
        $this->newModelName = '';
        $this->newModelPonType = '';
        $this->showModelModal = true;
    }

    public function addManufacturer(): void
    {
        $this->authorize('viewAny', OltDevice::class);

        $this->validate([
            'newManufacturerName' => ['required', 'string', 'max:255', 'unique:olt_manufacturers,name'],
        ]);

        $manufacturer = OltManufacturer::create(['name' => $this->newManufacturerName]);

        $this->oltManufacturerId = $manufacturer->id;
        $this->newManufacturerName = '';
        $this->showManufacturerModal = false;
    }

    public function addModel(): void
    {
        $this->authorize('viewAny', OltDevice::class);

        $this->validate([
            'oltManufacturerId' => ['required', 'exists:olt_manufacturers,id'],
            'newModelName' => ['required', 'string', 'max:255'],
            'newModelPonType' => ['required', 'in:'.implode(',', array_map(fn ($c) => $c->value, OltPonType::cases()))],
        ]);

        $model = OltModel::create([
            'olt_manufacturer_id' => $this->oltManufacturerId,
            'name' => $this->newModelName,
            'supported_pon_type' => $this->newModelPonType,
        ]);

        $this->oltModelId = $model->id;
        $this->newModelName = '';
        $this->newModelPonType = '';
        $this->showModelModal = false;
    }

    /**
     * Addendum #3 — a manufacturer can only be deleted while it has zero
     * models under it (force deleting models individually first). This is
     * a deliberately simpler/stricter rule than relying on
     * `olt_models.olt_manufacturer_id`'s DB-level `cascadeOnDelete()` (see
     * that migration) — cascading through to silently delete models (and
     * transitively rely on `olt_devices.olt_model_id`'s `restrictOnDelete()`
     * to block the whole transaction if any of THOSE models has a device)
     * would work, but "delete manufacturer" silently removing models the
     * user never explicitly asked to delete is more surprising than just
     * requiring an empty manufacturer — this module is create+delete only
     * per the sprint brief, no cascade UX was asked for.
     */
    public function deleteManufacturer(int $manufacturerId): void
    {
        $this->authorize('viewAny', OltDevice::class);

        $modelCount = OltModel::query()->where('olt_manufacturer_id', $manufacturerId)->count();

        if ($modelCount > 0) {
            $this->manufacturerDeleteError = "Tidak bisa dihapus — manufacturer ini masih punya {$modelCount} model. Hapus model-modelnya dulu.";

            return;
        }

        OltManufacturer::whereKey($manufacturerId)->delete();

        if ($this->oltManufacturerId === $manufacturerId) {
            $this->oltManufacturerId = null;
            $this->oltModelId = null;
        }

        $this->manufacturerDeleteError = null;
    }

    /**
     * Referential integrity against `olt_devices` — checked with
     * withoutGlobalScopes() deliberately: olt_models/olt_manufacturers are
     * platform-level master data (not tenant-scoped, same posture as
     * `payment_gateway_channels`/`cpe_parameter_maps`), so "is this model
     * still in use" must mean ACROSS every tenant/reseller, never just
     * whatever the acting admin's own TenantScope/reseller context can
     * currently see — undercounting here could let someone delete a model
     * a DIFFERENT tenant's real OLT device still points to.
     */
    public function deleteModel(int $modelId): void
    {
        $this->authorize('viewAny', OltDevice::class);

        $deviceCount = OltDevice::withoutGlobalScopes()->where('olt_model_id', $modelId)->count();

        if ($deviceCount > 0) {
            $this->modelDeleteError = "Tidak bisa dihapus — masih dipakai oleh {$deviceCount} perangkat OLT.";

            return;
        }

        OltModel::whereKey($modelId)->delete();

        if ($this->oltModelId === $modelId) {
            $this->oltModelId = null;
        }

        $this->modelDeleteError = null;
    }

    public function save(OltDeviceService $service): void
    {
        $context = app(ResellerContext::class);
        $isAdmin = $this->isAdmin();

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'nasId' => ['required', 'exists:nas,id'],
            'ipAddress' => ['required', 'ip'],
            'oltModelId' => ['required', 'exists:olt_models,id'],
            'accessProtocol' => ['required', 'in:'.implode(',', array_map(fn ($c) => $c->value, OltAccessProtocol::cases()))],
            // SNMP is always-on (addendum #2), never conditional on
            // accessProtocol — version/port are plain required fields;
            // RO community is required only on CREATE (auto-generated by
            // create() above, so it's never actually blank unless the
            // user deliberately clears it) — on EDIT, blank means "keep
            // the already-saved value", same masked-secret convention as
            // telnet_password/ssh_password. RW stays optional either way.
            'snmpVersion' => ['required', 'in:v1,v2c,v3'],
            'snmpPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'snmpRoCommunity' => [$this->editingOltDeviceId === null ? 'required' : 'nullable', 'string', 'max:255'],
            'snmpRwCommunity' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];

        if ($isAdmin) {
            $rules['resellerId'] = ['nullable', 'exists:resellers,id'];
        }

        if ($this->accessProtocol === OltAccessProtocol::Telnet->value) {
            $rules['telnetPort'] = ['required', 'integer', 'min:1', 'max:65535'];
            $rules['telnetUsername'] = ['required', 'string', 'max:255'];
        }

        if ($this->accessProtocol === OltAccessProtocol::Ssh->value) {
            $rules['sshPort'] = ['required', 'integer', 'min:1', 'max:65535'];
            $rules['sshUsername'] = ['required', 'string', 'max:255'];
        }

        $this->validate($rules);

        // BOSS-005 — never a public IP, these OLTs only ever exist on a
        // private management network reached via the assigned NAS.
        if (! OltDeviceService::isPrivateIp($this->ipAddress)) {
            $this->addError('ipAddress', 'IP Address harus berupa IP private (10.x, 172.16-31.x, atau 192.168.x) — OLT tidak boleh diakses lewat IP publik.');

            return;
        }

        // Save is BLOCKED until Test Connection has succeeded for the
        // EXACT (nas_id, ip_address) combo currently in the form — see
        // updated() above for how this gets invalidated on change.
        if ($this->testPassedForKey !== "{$this->nasId}|{$this->ipAddress}") {
            $this->addError('ipAddress', 'Klik "Test Connection" dan pastikan berhasil sebelum menyimpan — kombinasi Router + IP ini belum diverifikasi.');

            return;
        }

        // For the new-protocol path only carrying the relevant fields —
        // the OTHER protocols' columns stay whatever they already were
        // (null on create, untouched on update), never accidentally
        // clobbered by fields the current form state doesn't even show.
        $data = [
            'name' => $this->name,
            'nas_id' => $this->nasId,
            'ip_address' => $this->ipAddress,
            'olt_model_id' => $this->oltModelId,
            'access_protocol' => $this->accessProtocol,
            'notes' => $this->notes !== '' ? $this->notes : null,
        ];

        if ($this->accessProtocol === OltAccessProtocol::Telnet->value) {
            $data['telnet_port'] = $this->telnetPort;
            $data['telnet_username'] = $this->telnetUsername;
            if ($this->telnetPassword !== '') {
                $data['telnet_password'] = $this->telnetPassword;
            }
        }

        if ($this->accessProtocol === OltAccessProtocol::Ssh->value) {
            $data['ssh_port'] = $this->sshPort;
            $data['ssh_username'] = $this->sshUsername;
            if ($this->sshPassword !== '') {
                $data['ssh_password'] = $this->sshPassword;
            }
        }

        // SNMP is always-on now (addendum #2) — version/port are plain
        // fields, RO/RW communities follow the same blank-means-unchanged
        // masked-secret convention as telnet/ssh passwords above.
        $data['snmp_version'] = $this->snmpVersion;
        $data['snmp_port'] = $this->snmpPort;
        if ($this->snmpRoCommunity !== '') {
            $data['snmp_ro_community'] = $this->snmpRoCommunity;
        }
        if ($this->snmpRwCommunity !== '') {
            $data['snmp_rw_community'] = $this->snmpRwCommunity;
        }

        // Reuse the ALREADY-PASSED test result from the save-gate above
        // (testConnectionResult is guaranteed non-null and 'success' here
        // — the guard clause above would have returned already otherwise)
        // rather than re-running a fresh network call: it's the same
        // (nas_id, ip_address) combo that just passed, no reason to hit
        // the router twice for one save.
        $testOutcome = [
            'result' => OltConnectionTestResult::from($this->testConnectionResult['result']),
            'message' => $this->testConnectionResult['message'],
        ];

        if ($this->editingOltDeviceId === null) {
            $resellerId = $context->hasReseller() ? $context->reseller()->id : ($isAdmin ? $this->resellerId : null);

            $this->authorize('create', [OltDevice::class, $resellerId ? Reseller::find($resellerId) : null]);

            $oltDevice = $service->create($data, auth()->user()->tenant_id, $resellerId);
            session()->flash('status', 'OLT berhasil dibuat.');
        } else {
            $oltDevice = OltDevice::findOrFail($this->editingOltDeviceId);
            $this->authorize('manage', $oltDevice);

            $oltDevice = $service->update($oltDevice, $data);
            session()->flash('status', 'OLT berhasil diperbarui.');
        }

        $service->recordConnectionTest($oltDevice, $testOutcome);

        $this->resetForm();
        $this->showForm = false;
        $this->dispatch('olt-device-saved');
    }

    #[On('deleteOltDevice')]
    public function delete(int $oltDeviceId): void
    {
        $oltDevice = OltDevice::findOrFail($oltDeviceId);
        $this->authorize('manage', $oltDevice);

        app(OltDeviceService::class)->delete($oltDevice);

        session()->flash('status', 'OLT berhasil dihapus.');
        $this->dispatch('olt-device-saved');
    }

    private function resetForm(): void
    {
        $this->editingOltDeviceId = null;
        $this->name = '';
        $this->nasId = null;
        $this->ipAddress = '';
        $this->oltManufacturerId = null;
        $this->oltModelId = null;
        $this->accessProtocol = '';
        $this->telnetPort = 2333;
        $this->telnetUsername = '';
        $this->telnetPassword = '';
        $this->sshPort = 22;
        $this->sshUsername = '';
        $this->sshPassword = '';
        $this->snmpVersion = 'v2c';
        $this->snmpPort = 2161;
        $this->snmpRoCommunity = '';
        $this->snmpRwCommunity = '';
        $this->notes = '';
        $this->resellerId = null;
        $this->testConnectionResult = null;
        $this->testPassedForKey = null;
    }

    public function render()
    {
        return view('livewire.network.olt-device-index');
    }
}
