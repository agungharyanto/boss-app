<?php

namespace App\Livewire\Network;

use App\Exceptions\LibreNmsDataUnavailableException;
use App\Services\Network\LibreNmsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.8.2-monitoring-fixes — "Tambah Device" form for the Monitoring page.
 * Onboards a generic SNMP device (switch, server, anything with an SNMP
 * agent) directly into LibreNMS via LibreNmsService::addDevice() — the
 * device exists ONLY in LibreNMS afterward, deliberately NOT recorded in
 * `olt_devices`/`nas` (those are BOSS App's own credential registries for
 * devices this app ALSO talks to directly — an OLT via SNMP-from-the-NAS,
 * a NAS via its RouterOS API; a generic monitoring-only device has no such
 * BOSS-App-side counterpart, LibreNMS is the sole source of truth for it).
 * DeviceMonitoringList needs no code change to pick it up — it already
 * calls LibreNmsService::listDevices(), which queries LibreNMS for every
 * device LibreNMS knows about, not a BOSS-App-side filtered list (verified,
 * not assumed — see this sprint's own CLAUDE.md entry).
 *
 * `monitoring.manage` (new this sprint) gates this — `monitoring.view`
 * alone (the only permission that existed before) was deliberately
 * view-only, since LibreNmsService had no mutating method until now.
 *
 * No SNMP v3 (explicitly out of scope this sprint) and no edit/delete
 * (this form only ever creates) — see this class's own validate() rules
 * and CLAUDE.md for the full reasoning.
 */
class AddMonitoringDeviceForm extends Component
{
    use AuthorizesRequests;

    public bool $showModal = false;

    public string $hostname = '';

    public string $snmpVersion = 'v2c';

    public string $community = '';

    public int $port = 161;

    public string $displayName = '';

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public function mount(): void
    {
        $this->authorize('monitoring.manage');
    }

    public function openModal(): void
    {
        $this->authorize('monitoring.manage');

        $this->showModal = true;
        $this->errorMessage = null;
        $this->successMessage = null;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    public function save(LibreNmsService $service): void
    {
        $this->authorize('monitoring.manage');

        $this->errorMessage = null;
        $this->successMessage = null;

        // `community` is unconditionally required, not required_if — SNMP
        // v3 (the only version that wouldn't use a community string) isn't
        // a selectable option this sprint, see snmpVersion's own `in:`
        // rule, so a conditional rule here would be genuinely unreachable
        // dead logic.
        $validated = $this->validate([
            'hostname' => ['required', 'string', 'max:255'],
            'snmpVersion' => ['required', 'in:v1,v2c'],
            'community' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'displayName' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $service->addDevice(
                hostname: $validated['hostname'],
                snmpVersion: $validated['snmpVersion'],
                community: $validated['community'],
                port: $validated['port'],
                displayName: $validated['displayName'] !== '' ? $validated['displayName'] : null,
            );
        } catch (LibreNmsDataUnavailableException $e) {
            // LibreNMS's own real error text (e.g. "Could not ping ...",
            // "SNMP Timeout") — never paraphrased, so Agung sees exactly
            // what LibreNMS itself reported.
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->successMessage = "Device \"{$result['hostname']}\" berhasil ditambahkan.";
        $this->reset(['hostname', 'community', 'displayName']);
        $this->port = 161;
        $this->snmpVersion = 'v2c';

        // DeviceMonitoringList listens for this to reload its own list —
        // same "dispatch, sibling reloads" pattern already established
        // between it and DeviceTrafficGraph's device-selected event.
        $this->dispatch('monitoring-device-added');
    }

    public function render()
    {
        return view('livewire.network.add-monitoring-device-form');
    }
}
