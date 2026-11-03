<?php

namespace App\Livewire\Settings;

use App\Enums\WhatsappSessionStatus;
use App\Models\WhatsappGroupRoute;
use App\Models\WhatsappNotificationGroup;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings as WorkOrderDispatchSettingsModel;
use App\Services\Whatsapp\WhatsappSessionService;
use App\Support\Whatsapp\WhatsappGroupCategory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * "Komunikasi > Konfig WA Gateway". Permission: `work_orders.manage`
 * (tier-admin). Per-tenant.
 *
 * v0.13.4.4 — field tunggal "Pilih Grup WhatsApp" lama (`waGroupJid`/
 * `waGroupName`, kolom deprecated) DIGANTI dua bagian:
 *   - "Daftar Grup Notifikasi": beberapa grup (dari daftar grup bot sesi
 *     'direct'), tambah/ubah label/set default/aktif-nonaktif/hapus.
 *   - "Routing Notifikasi": kategori (WhatsappGroupCategory) → grup; kosong
 *     = pakai Default.
 * Grup default TIDAK bisa dihapus/dinonaktifkan selama ada grup lain (pilih
 * default baru dulu). UI ini hanya mengelola grup sesi 'direct'
 * (session_key='direct'); grup per-reseller = backlog.
 *
 * Daftar grup bot tetap via `WhatsappSessionService::listGroups()` di-cache
 * 15 menit; "Muat Ulang" bypass cache. Timing dispatch/reminder tetap di
 * `save()`.
 */
class WorkOrderDispatchSettings extends Component
{
    use AuthorizesRequests;

    private const GROUPS_CACHE_TTL_MINUTES = 15;

    public int $dispatchOffsetHours = 2;

    public string $reminderTime = '08:00';

    public int $commandIntervalMinutes = 15;

    /** Form "Tambah Grup". */
    public ?string $newGroupJid = null;

    public ?string $newGroupLabel = null;

    /** Nilai label yang terakhir kali DIISI-OTOMATIS (bukan diketik admin). */
    public ?string $autoFilledLabel = null;

    /** Label per grup (editable inline), keyed by group id. */
    public array $groupLabels = [];

    /** Routing kategori → group_id ('' = Default), keyed by category string. */
    public array $routeGroupId = [];

    public bool $sessionConnected = false;

    public bool $groupsLoadFailed = false;

    public function mount(): void
    {
        $this->authorize('work_orders.manage');

        $this->refreshFromSettings(WorkOrderDispatchSettingsModel::forTenant($this->tenantId()));
        $this->loadGroupState();
        $this->syncGroupLabels();
        $this->loadRoutes();
    }

    public function save(): void
    {
        $this->authorize('work_orders.manage');

        $this->validate([
            'dispatchOffsetHours' => ['required', 'integer', 'min:1'],
            'reminderTime' => ['required', 'date_format:H:i'],
            'commandIntervalMinutes' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        $settings = WorkOrderDispatchSettingsModel::forTenant($this->tenantId());
        $settings->update([
            'dispatch_offset_minutes' => $this->dispatchOffsetHours * 60,
            'reminder_time' => $this->reminderTime,
            'command_interval_minutes' => $this->commandIntervalMinutes,
        ]);

        $this->refreshFromSettings($settings->fresh());

        session()->flash('status', 'Pengaturan timing dispatch/reminder berhasil disimpan.');
    }

    // ── Daftar Grup Notifikasi ─────────────────────────────────────────

    public function updatedNewGroupJid(): void
    {
        if ($this->newGroupJid === '' || $this->newGroupJid === null) {
            // Reset label HANYA bila masih hasil auto-fill — jangan hapus
            // yang sudah diketik admin.
            if ($this->newGroupLabel === null || $this->newGroupLabel === $this->autoFilledLabel) {
                $this->newGroupLabel = null;
            }
            $this->autoFilledLabel = null;

            return;
        }

        // Nama ASLI grup dari daftar bot (cache), cocokkan by JID.
        $realName = collect($this->groupOptions())->firstWhere('jid', $this->newGroupJid)['name'] ?? null;
        if ($realName === null) {
            return;
        }

        // Prefill nama asli, KECUALI admin sudah mengetik label manual
        // (label sekarang tidak kosong DAN bukan hasil auto-fill terakhir).
        if (trim((string) $this->newGroupLabel) === '' || $this->newGroupLabel === $this->autoFilledLabel) {
            $this->newGroupLabel = $realName;
            $this->autoFilledLabel = $realName;
        }
    }

    public function addGroup(): void
    {
        $this->authorize('work_orders.manage');

        $this->validate([
            'newGroupJid' => ['required', 'string', 'max:255'],
            'newGroupLabel' => ['required', 'string', 'max:255'],
        ], [
            'newGroupJid.required' => 'Pilih grup dari dropdown dulu.',
        ], ['newGroupJid' => 'grup', 'newGroupLabel' => 'label']);

        // JID yang ditambah HARUS ada di daftar grup bot saat ini.
        $jids = array_column($this->groupOptions(), 'jid');
        if (! in_array($this->newGroupJid, $jids, true)) {
            $this->addError('newGroupJid', 'Grup itu tidak ada di daftar grup bot. Muat Ulang daftar grup lalu coba lagi.');

            return;
        }

        $tenantId = $this->tenantId();

        $already = WhatsappNotificationGroup::query()
            ->where('tenant_id', $tenantId)
            ->where('jid', $this->newGroupJid)
            ->exists();
        if ($already) {
            $this->addError('newGroupJid', 'Grup itu sudah ada di daftar grup notifikasi.');

            return;
        }

        // Grup pertama tenant ini (sesi direct) otomatis jadi default.
        $isFirst = ! WhatsappNotificationGroup::query()
            ->where('tenant_id', $tenantId)
            ->where('session_key', 'direct')
            ->exists();

        WhatsappNotificationGroup::create([
            'tenant_id' => $tenantId,
            'session_key' => 'direct',
            'jid' => $this->newGroupJid,
            'label' => $this->newGroupLabel,
            'is_default' => $isFirst,
            'is_active' => true,
        ]);

        $this->newGroupJid = null;
        $this->newGroupLabel = null;
        $this->syncGroupLabels();

        session()->flash('status', 'Grup ditambahkan.');
    }

    public function saveLabel(int $groupId): void
    {
        $this->authorize('work_orders.manage');

        $group = $this->ownGroup($groupId);
        if ($group === null) {
            return;
        }

        $label = trim((string) ($this->groupLabels[$groupId] ?? ''));
        if ($label === '') {
            $this->addError('groupLabels.'.$groupId, 'Label tidak boleh kosong.');

            return;
        }

        $group->update(['label' => $label]);
        session()->flash('status', 'Label disimpan.');
    }

    public function setDefault(int $groupId): void
    {
        $this->authorize('work_orders.manage');

        $group = $this->ownGroup($groupId);
        if ($group === null) {
            return;
        }

        // Unset default lama DULU (partial unique index one-default), lalu set
        // yang ini. Default wajib aktif.
        DB::transaction(function () use ($group) {
            WhatsappNotificationGroup::query()
                ->where('tenant_id', $group->tenant_id)
                ->where('session_key', 'direct')
                ->where('id', '!=', $group->id)
                ->update(['is_default' => false]);

            $group->update(['is_default' => true, 'is_active' => true]);
        });

        session()->flash('status', "Default diubah ke \"{$group->label}\".");
    }

    public function toggleActive(int $groupId): void
    {
        $this->authorize('work_orders.manage');

        $group = $this->ownGroup($groupId);
        if ($group === null) {
            return;
        }

        if ($group->is_active && $group->is_default && $this->otherGroupsExist($group)) {
            session()->flash('error', 'Grup Default tidak bisa dinonaktifkan selama ada grup lain. Pilih Default baru dulu.');

            return;
        }

        $nowActive = ! $group->is_active;
        $group->update(['is_active' => $nowActive]);
        session()->flash('status', $nowActive ? 'Grup diaktifkan.' : 'Grup dinonaktifkan.');
    }

    public function deleteGroup(int $groupId): void
    {
        $this->authorize('work_orders.manage');

        $group = $this->ownGroup($groupId);
        if ($group === null) {
            return;
        }

        if ($group->is_default && $this->otherGroupsExist($group)) {
            session()->flash('error', 'Grup Default tidak bisa dihapus selama ada grup lain. Pilih Default baru dulu.');

            return;
        }

        // Routing yang menunjuk grup ini otomatis jadi null (nullOnDelete) →
        // jatuh ke Default.
        $group->delete();
        $this->syncGroupLabels();
        $this->loadRoutes();

        session()->flash('status', 'Grup dihapus.');
    }

    // ── Routing Notifikasi ─────────────────────────────────────────────

    /**
     * Routing TIDAK auto-save. Dropdown pakai `wire:model.live` hanya agar
     * penanda "ada perubahan belum disimpan" + tombol Simpan ikut reaktif
     * (roundtrip me-render ulang, BUKAN menyimpan ke DB). Persist hanya lewat
     * tombol "Simpan Routing" → `saveRouting()`. Tidak ada hook `updated*`
     * yang menulis ke DB (sengaja — supaya perubahan dropdown tidak tersimpan
     * diam-diam).
     */
    public function saveRouting(): void
    {
        $this->authorize('work_orders.manage');

        $tenantId = $this->tenantId();
        $labels = WhatsappGroupCategory::labels();

        // Validasi: grup tujuan harus grup aktif milik tenant ini (sesi
        // direct); kosong = Default (null).
        foreach ($this->routeGroupId as $category => $value) {
            if (! array_key_exists($category, $labels)) {
                continue;
            }
            if ($value === '' || $value === null) {
                continue;
            }

            $group = $this->ownGroup((int) $value);
            if ($group === null || ! $group->is_active) {
                $this->addError('routeGroupId.'.$category, 'Grup tujuan harus grup aktif milik Anda.');

                return;
            }
        }

        DB::transaction(function () use ($tenantId, $labels) {
            foreach (array_keys($labels) as $category) {
                $value = $this->routeGroupId[$category] ?? '';
                $groupId = ($value === '' || $value === null) ? null : (int) $value;

                WhatsappGroupRoute::updateOrCreate(
                    ['tenant_id' => $tenantId, 'category' => $category],
                    ['group_id' => $groupId],
                );
            }
        });

        // Normalisasi state lokal ke hasil DB → penanda "belum disimpan" hilang.
        $this->loadRoutes();

        session()->flash('status', 'Routing disimpan.');
    }

    /**
     * True bila pilihan routing lokal berbeda dari yang tersimpan di DB —
     * dipakai untuk mengaktifkan tombol Simpan Routing + penanda perubahan.
     */
    public function routingDirty(): bool
    {
        return $this->routeGroupId != $this->savedRoutes();
    }

    public function reloadGroups(): void
    {
        $this->authorize('work_orders.manage');

        Cache::forget($this->groupsCacheKey());
        $this->loadGroupState();

        session()->flash('status', 'Daftar grup dimuat ulang.');
    }

    public function render()
    {
        return view('livewire.settings.work-order-dispatch-settings', [
            // Fetch/cache daftar grup bot CUMA kalau sesi connected (sama
            // alasan v0.26.4 — hindari menembak gateway untuk sesi belum
            // connected).
            'groupOptions' => $this->sessionConnected ? $this->groupOptions() : [],
            'notificationGroups' => $this->notificationGroups(),
            'categoryLabels' => WhatsappGroupCategory::labels(),
            'routingDirty' => $this->routingDirty(),
            // Nama asli grup WA per-JID (dari cache daftar bot) untuk teks
            // informatif "Nama di WhatsApp: …" di tiap baris.
            'botGroupNamesByJid' => collect($this->sessionConnected ? $this->groupOptions() : [])
                ->pluck('name', 'jid')
                ->all(),
        ]);
    }

    private function refreshFromSettings(WorkOrderDispatchSettingsModel $settings): void
    {
        $this->dispatchOffsetHours = intdiv($settings->dispatch_offset_minutes, 60);
        $this->reminderTime = $settings->reminder_time;
        $this->commandIntervalMinutes = $settings->command_interval_minutes;
    }

    private function tenantId(): int
    {
        return auth()->user()->tenant_id;
    }

    /**
     * Grup sesi 'direct' milik tenant ini — default dulu, lalu label.
     *
     * @return Collection<int, WhatsappNotificationGroup>
     */
    private function notificationGroups()
    {
        return WhatsappNotificationGroup::query()
            ->where('tenant_id', $this->tenantId())
            ->where('session_key', 'direct')
            ->orderByDesc('is_default')
            ->orderBy('label')
            ->get();
    }

    private function ownGroup(int $groupId): ?WhatsappNotificationGroup
    {
        return WhatsappNotificationGroup::query()
            ->where('tenant_id', $this->tenantId())
            ->where('session_key', 'direct')
            ->whereKey($groupId)
            ->first();
    }

    private function otherGroupsExist(WhatsappNotificationGroup $group): bool
    {
        return WhatsappNotificationGroup::query()
            ->where('tenant_id', $group->tenant_id)
            ->where('session_key', 'direct')
            ->where('id', '!=', $group->id)
            ->exists();
    }

    private function syncGroupLabels(): void
    {
        $this->groupLabels = $this->notificationGroups()
            ->mapWithKeys(fn (WhatsappNotificationGroup $g) => [$g->id => $g->label])
            ->all();
    }

    private function loadRoutes(): void
    {
        $this->routeGroupId = $this->savedRoutes();
    }

    /**
     * Pilihan routing yang TERSIMPAN di DB, dalam bentuk yang sama dengan
     * `$routeGroupId` ('' = Default), per kategori.
     *
     * @return array<string, string>
     */
    private function savedRoutes(): array
    {
        $existing = WhatsappGroupRoute::query()
            ->where('tenant_id', $this->tenantId())
            ->pluck('group_id', 'category');

        $routes = [];
        foreach (array_keys(WhatsappGroupCategory::labels()) as $category) {
            $groupId = $existing[$category] ?? null;
            $routes[$category] = $groupId === null ? '' : (string) $groupId;
        }

        return $routes;
    }

    /**
     * @return array<int, array{jid: string, name: string}>
     */
    private function groupOptions(): array
    {
        return Cache::remember(
            $this->groupsCacheKey(),
            now()->addMinutes(self::GROUPS_CACHE_TTL_MINUTES),
            fn () => $this->fetchGroupsFromGateway(),
        );
    }

    private function groupsCacheKey(): string
    {
        return 'whatsapp:groups:tenant:'.$this->tenantId();
    }

    private function loadGroupState(): void
    {
        $session = $this->directSession();

        $this->sessionConnected = $session !== null && $session->status === WhatsappSessionStatus::Connected;

        if (! $this->sessionConnected) {
            $this->groupsLoadFailed = false;

            return;
        }

        $this->groupsLoadFailed = $this->groupOptions() === [];
    }

    /**
     * @return array<int, array{jid: string, name: string}>
     */
    private function fetchGroupsFromGateway(): array
    {
        $session = $this->directSession();

        if ($session === null) {
            return [];
        }

        return app(WhatsappSessionService::class)->listGroups($session);
    }

    private function directSession(): ?WhatsappSession
    {
        return WhatsappSession::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId())
            ->whereNull('reseller_id')
            ->first();
    }
}
