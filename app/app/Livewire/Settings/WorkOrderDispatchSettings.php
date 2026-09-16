<?php

namespace App\Livewire\Settings;

use App\Models\WorkOrderDispatchSettings as WorkOrderDispatchSettingsModel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * v0.26.2 — "Komunikasi > Konfig WA Gateway" (nama halaman sesuai kickoff,
 * meski isinya settings timing dispatch/reminder Work Order, BUKAN
 * pengaturan gateway WA itu sendiri — lihat docblock
 * `WorkOrderDispatchSettings` model untuk kenapa tabelnya sengaja bukan
 * `wa_gateway_settings`). Per-tenant — `auth()->user()->tenant_id`, bukan
 * platform-level singleton seperti `PaymentGatewaySettings`.
 *
 * Permission: `work_orders.manage` (tier-admin, sudah ada sejak v0.5.0) —
 * dikonfirmasi Langkah 0, TIDAK ada permission baru dibuat untuk halaman
 * ini.
 *
 * `waGroupJid` SENGAJA ditampilkan disabled dengan placeholder — field-nya
 * ADA di form supaya admin tahu field itu akan ada, tapi tidak bisa diisi
 * sampai kapabilitas kirim-ke-grup ada di gateway Go (v0.26.4, dikunci
 * eksplisit di scope v0.26.2 — JANGAN diisi/dibuat kapabilitasnya di sini).
 */
class WorkOrderDispatchSettings extends Component
{
    use AuthorizesRequests;

    public int $dispatchOffsetHours = 2;

    public string $reminderTime = '08:00';

    public int $commandIntervalMinutes = 15;

    public ?string $waGroupName = null;

    public function mount(): void
    {
        $this->authorize('work_orders.manage');

        $this->refreshFromSettings(WorkOrderDispatchSettingsModel::forTenant(auth()->user()->tenant_id));
    }

    public function save(): void
    {
        $this->authorize('work_orders.manage');

        $this->validate([
            'dispatchOffsetHours' => ['required', 'integer', 'min:1'],
            'reminderTime' => ['required', 'date_format:H:i'],
            'commandIntervalMinutes' => ['required', 'integer', 'min:1', 'max:60'],
            'waGroupName' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = WorkOrderDispatchSettingsModel::forTenant(auth()->user()->tenant_id);
        $settings->update([
            'dispatch_offset_minutes' => $this->dispatchOffsetHours * 60,
            'reminder_time' => $this->reminderTime,
            'command_interval_minutes' => $this->commandIntervalMinutes,
            'wa_group_name' => $this->waGroupName !== '' ? $this->waGroupName : null,
        ]);

        $this->refreshFromSettings($settings->fresh());

        session()->flash('status', 'Pengaturan Konfig WA Gateway berhasil disimpan.');
    }

    public function render()
    {
        return view('livewire.settings.work-order-dispatch-settings');
    }

    private function refreshFromSettings(WorkOrderDispatchSettingsModel $settings): void
    {
        $this->dispatchOffsetHours = intdiv($settings->dispatch_offset_minutes, 60);
        $this->reminderTime = $settings->reminder_time;
        $this->commandIntervalMinutes = $settings->command_interval_minutes;
        $this->waGroupName = $settings->wa_group_name;
    }
}
