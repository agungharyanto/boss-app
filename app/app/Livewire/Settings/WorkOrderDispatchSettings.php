<?php

namespace App\Livewire\Settings;

use App\Enums\WhatsappSessionStatus;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings as WorkOrderDispatchSettingsModel;
use App\Services\Whatsapp\WhatsappSessionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
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
 * v0.26.4 — dropdown "Pilih Grup WhatsApp" MENGGANTIKAN 2 field manual
 * lama (`waGroupName` input teks bebas, `waGroupJid` disabled placeholder).
 * Hasil fetch `WhatsappSessionService::listGroups()` (sesi "direct" tenant
 * ini — settings ini per-tenant, bukan per-reseller, sama posture kolom
 * `wa_group_jid`/`wa_group_name` itu sendiri) di-cache 15 menit
 * (`Cache::remember`) — daftar grup jarang berubah (bot masuk/keluar grup
 * adalah aksi manusia manual, bukan event rutin), tombol "Muat Ulang"
 * bypass cache eksplisit lewat `Cache::forget()`. Memilih dropdown mengisi
 * KEDUA `waGroupJid` (value fungsional yang genuinely disimpan & dipakai
 * kirim) DAN `waGroupName` (cache nama untuk ditampilkan tanpa fetch ulang
 * — TETAP kolom terpisah di DB, bukan dihapus, cuma sekarang di-auto-fill
 * dari dropdown alih-alih diketik manual).
 */
class WorkOrderDispatchSettings extends Component
{
    use AuthorizesRequests;

    private const GROUPS_CACHE_TTL_MINUTES = 15;

    public int $dispatchOffsetHours = 2;

    public string $reminderTime = '08:00';

    public int $commandIntervalMinutes = 15;

    public ?string $waGroupName = null;

    public ?string $waGroupJid = null;

    /**
     * State kosong eksplisit — dropdown grup TIDAK pernah silently kosong
     * tanpa penjelasan (lihat blade view: pesan beda untuk masing-masing).
     */
    public bool $sessionConnected = false;

    public bool $groupsLoadFailed = false;

    public function mount(): void
    {
        $this->authorize('work_orders.manage');

        $this->refreshFromSettings(WorkOrderDispatchSettingsModel::forTenant(auth()->user()->tenant_id));
        $this->loadGroupState();
    }

    public function save(): void
    {
        $this->authorize('work_orders.manage');

        $this->validate([
            'dispatchOffsetHours' => ['required', 'integer', 'min:1'],
            'reminderTime' => ['required', 'date_format:H:i'],
            'commandIntervalMinutes' => ['required', 'integer', 'min:1', 'max:60'],
            'waGroupJid' => ['nullable', 'string', 'max:255'],
        ]);

        $settings = WorkOrderDispatchSettingsModel::forTenant(auth()->user()->tenant_id);
        $settings->update([
            'dispatch_offset_minutes' => $this->dispatchOffsetHours * 60,
            'reminder_time' => $this->reminderTime,
            'command_interval_minutes' => $this->commandIntervalMinutes,
            'wa_group_jid' => $this->waGroupJid !== '' ? $this->waGroupJid : null,
            // wa_group_name = cache nama untuk ditampilkan — dropdown
            // sendiri yang menjaga kedua field ini sinkron lewat
            // updatedWaGroupJid(), kosong kalau waGroupJid juga kosong.
            'wa_group_name' => $this->waGroupJid !== '' ? $this->waGroupName : null,
        ]);

        $this->refreshFromSettings($settings->fresh());

        session()->flash('status', 'Pengaturan Konfig WA Gateway berhasil disimpan.');
    }

    /**
     * Dropdown dipilih — isi waGroupName dari opsi grup yang cocok
     * (cache nama tampilan), TIDAK fetch ulang API. Kalau JID yang dipilih
     * entah bagaimana tidak lagi ada di daftar saat ini (jarang — cache
     * basi antara render awal dan klik), waGroupName dikosongkan supaya
     * tidak menampilkan nama basi untuk JID yang mungkin sudah beda.
     */
    public function updatedWaGroupJid(): void
    {
        if ($this->waGroupJid === '' || $this->waGroupJid === null) {
            $this->waGroupName = null;

            return;
        }

        $match = collect($this->groupOptions())->firstWhere('jid', $this->waGroupJid);
        $this->waGroupName = $match['name'] ?? null;
    }

    /**
     * Tombol "Muat Ulang Daftar Grup" — bypass cache eksplisit.
     */
    public function reloadGroups(): void
    {
        Cache::forget($this->groupsCacheKey());
        $this->loadGroupState();
    }

    public function render()
    {
        return view('livewire.settings.work-order-dispatch-settings', [
            // Fetch/cache CUMA dipanggil kalau sesi genuinely connected —
            // bug nyata ditemukan test (v0.26.4): versi awal method ini
            // selalu memanggil groupOptions() tanpa syarat, jadi tetap
            // menembak gateway (dan gagal dengan koneksi error) untuk
            // tenant yang sesinya belum connected sama sekali, walau
            // Blade view sendiri sudah benar tidak MENAMPILKAN hasilnya.
            'groupOptions' => $this->sessionConnected ? $this->groupOptions() : [],
        ]);
    }

    private function refreshFromSettings(WorkOrderDispatchSettingsModel $settings): void
    {
        $this->dispatchOffsetHours = intdiv($settings->dispatch_offset_minutes, 60);
        $this->reminderTime = $settings->reminder_time;
        $this->commandIntervalMinutes = $settings->command_interval_minutes;
        $this->waGroupName = $settings->wa_group_name;
        $this->waGroupJid = $settings->wa_group_jid;
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
        return 'whatsapp:groups:tenant:'.auth()->user()->tenant_id;
    }

    /**
     * Sesi "direct" tenant ini — settings ini per-tenant (bukan
     * per-reseller), sama posture kolom wa_group_jid/wa_group_name sendiri.
     * Set sessionConnected/groupsLoadFailed di sini (bukan properti hasil
     * closure Cache::remember() — flag status TIDAK ikut ter-cache, harus
     * selalu mencerminkan kondisi NYATA saat render ini, bukan status dari
     * 15 menit lalu).
     */
    private function loadGroupState(): void
    {
        $session = WhatsappSession::withoutGlobalScopes()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->whereNull('reseller_id')
            ->first();

        $this->sessionConnected = $session !== null && $session->status === WhatsappSessionStatus::Connected;

        if (! $this->sessionConnected) {
            $this->groupsLoadFailed = false;

            return;
        }

        $groups = $this->groupOptions();
        $this->groupsLoadFailed = $groups === [];
    }

    /**
     * @return array<int, array{jid: string, name: string}>
     */
    private function fetchGroupsFromGateway(): array
    {
        $session = WhatsappSession::withoutGlobalScopes()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->whereNull('reseller_id')
            ->first();

        if ($session === null) {
            return [];
        }

        return app(WhatsappSessionService::class)->listGroups($session);
    }
}
