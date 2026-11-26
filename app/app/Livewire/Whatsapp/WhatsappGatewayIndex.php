<?php

namespace App\Livewire\Whatsapp;

use App\Enums\WhatsappEventType;
use App\Models\Reseller;
use App\Models\WhatsappGatewaySettings as WhatsappGatewaySettingsModel;
use App\Models\WhatsappIncomingMessage;
use App\Models\WhatsappMessageLog;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappSession;
use App\Models\WorkOrderDispatchSettings;
use App\Services\Whatsapp\CustomerAutoSendSettingsService;
use App\Services\Whatsapp\WhatsappGatewayService;
use App\Services\Whatsapp\WhatsappSessionService;
use App\Services\Whatsapp\WhatsappTemplateService;
use App\Support\ResellerContext;
use App\Support\Whatsapp\DueReminderDays;
use App\Support\WhatsappPhone;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Single component, branching by context (same pattern as
 * App\Livewire\Tax\ResellerTaxPolicyIndex): a reseller owner/staff sees
 * their own session/templates/queue only (WhatsappSession/Template/Log's
 * BelongsToResellerScope narrows every query automatically); an ISP admin
 * (whatsapp_gateway.view/.manage) sees every session + manages the ISP-level
 * default templates + the combined queue + the global rate-limit settings.
 */
class WhatsappGatewayIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $tab = 'konfigurasi';

    public string $editingEventType = '';

    public string $editingContent = '';

    public bool $editingIsActive = true;

    public bool $showTemplateForm = false;

    public string $statusFilter = '';

    // Dipakai BERSAMA oleh tab Pesan Keluar (WhatsappMessageLog.reseller_id) DAN
    // tab Pesan Masuk (WhatsappIncomingMessage.reseller_id, v0.13.1
    // perluasan) — satu properti, sesuai instruksi reuse PERSIS pola filter
    // yang sama; keduanya adalah kolom `reseller_id` (int) dengan makna
    // yang identik (admin-only, "Semua Reseller" default). Kolom baru
    // $incomingSessionFilter yang sempat ada sebelum keputusan reuse ini
    // dihapus lagi.
    public ?int $resellerFilter = null;

    public int $rateLimitDelayMin = 5;

    public int $rateLimitDelayMax = 10;

    public int $rateLimitBatchSize = 20;

    public int $rateLimitBatchPauseMin = 5;

    public int $rateLimitBatchPauseMax = 10;

    public string $dailyScheduleTimesRaw = '08:00,20:00';

    // --- Kode Pairing (alternatif Scan QR, sprint "whatsapp-gateway-reliability") ---

    /** id sesi yang mode "Pakai Kode Pairing"-nya sedang dibuka (bukan boolean global — tiap sesi independen). */
    public ?int $pairingModeSessionId = null;

    public string $pairingPhoneNumber = '';

    public ?string $pairingCodeResult = null;

    /** id sesi yang kode pairing-nya baru saja diterbitkan — supaya tampil di panel yang benar. */
    public ?int $pairingCodeSessionId = null;

    /**
     * Reseller yang login sebagai (dari App\Support\ResellerContext, null =
     * admin/staff langsung). Resolve SEKALI di mount(), disimpan sebagai
     * public property — BUKAN dipanggil ulang lewat app(ResellerContext::class)
     * tiap render() (seperti sebelumnya). ResellerContext di-resolve oleh
     * middleware `reseller.context`, yang HANYA berjalan di route GET awal
     * (`/whatsapp-gateway`, routes/web.php) — route internal Livewire
     * (`POST .../livewire/update`, didaftarkan package Livewire sendiri,
     * bukan lewat routes/web.php) TIDAK PERNAH dibungkus middleware itu.
     * Dikonfirmasi langsung: `php artisan route:list -vv` untuk route itu
     * cuma menunjukkan EncryptCookies/AddQueuedCookiesToResponse/
     * StartSession/ShareErrorsFromSession/ValidateCsrfToken/
     * SubstituteBindings/SetLocale/RequireLivewireHeaders — nol
     * reseller.context. Akibatnya `app(ResellerContext::class)->hasReseller()`
     * SELALU false di request Livewire manapun SETELAH request GET pertama
     * (klik tab, dst) — bug nyata, bukan cuma soal tab "Pesan Masuk"
     * (ditemukan v0.13.1, tapi PRE-EXISTING, bukan diperkenalkan sub-versi
     * ini): $mySession dan $resellerIdForTemplates di render() ikut rusak
     * dengan cara yang sama. Livewire otomatis mem-persist PUBLIC PROPERTY
     * lewat siklus hydrate/dehydrate antar request (bukan lewat middleware
     * apa pun) — itu sebabnya resolve sekali + simpan properti adalah
     * satu-satunya cara nilainya tetap benar di render() kedua dst.
     *
     * Method AKSI (createSession/editTemplate/saveTemplate/
     * resetTemplateToDefault) punya bug KELAS SAMA (masing-masing dulu
     * panggil app(ResellerContext::class) sendiri, ditemukan lewat grep
     * menyeluruh — persis 4 method ini yang punya pola itu) — SUDAH
     * DIPERBAIKI juga, semuanya baca $this->resellerId, tidak pernah
     * resolve ulang dari container setelah mount().
     */
    public ?int $resellerId = null;

    // ── Tab "Kirim Otomatis" (v0.28.5) — per tenant, admin-only ──────────
    // Kartu (a) saklar pelanggan + Mode Uji + kirim invoice otomatis.
    public bool $autoNotifyEnabled = true;

    public bool $testModeEnabled = false;

    public ?string $testRecipient = null;

    public bool $invoiceAutoSendEnabled = false;

    public ?string $invoiceAutoSendFrom = null;

    // Kartu (b) jadwal hari pengingat jatuh tempo (H-0..H-7); array string.
    /** @var array<int, string> */
    public array $reminderDays = [];

    // Kartu (c) kontrol volume.
    public int $dailyCap = 80;

    public string $windowStart = '08:00';

    public string $windowEnd = '20:00';

    public int $breakerThreshold = 10;

    /**
     * Modal konfirmasi aksi berisiko (menambah paparan ke pelanggan nyata):
     * 'auto_notify' (nyalakan), 'test_mode_off' (matikan Mode Uji),
     * 'invoice_auto_send' (nyalakan). null = tidak ada modal. Mematikan saklar
     * kirim / menyalakan Mode Uji TIDAK pakai modal.
     */
    public ?string $confirmAction = null;

    public function mount(): void
    {
        $this->authorize('viewAny', WhatsappSession::class);

        $context = app(ResellerContext::class);
        $this->resellerId = $context->hasReseller() ? $context->reseller()->id : null;

        if ($this->isAdmin()) {
            $this->tab = 'overview';
            $this->loadSettingsIntoForm();
            $this->loadAutoSendIntoForm();
        }
    }

    public function isAdmin(): bool
    {
        return auth()->user()->can('whatsapp_gateway.manage') || auth()->user()->can('whatsapp_gateway.view');
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    /**
     * "Hubungkan Nomor" button — creates the whatsapp_sessions row for the
     * acting user's own scope (admin -> the "direct" session, reseller ->
     * their own reseller_id) and kicks off the gateway-side connect.
     * Authorization is scope-exact: WhatsappSessionPolicy::manage() only
     * allows an admin to create/manage the direct session, never a
     * reseller's — and a reseller only their own.
     */
    public function createSession(WhatsappSessionService $service): void
    {
        $isAdmin = $this->isAdmin();
        $resellerId = $this->resellerId;

        if (! $isAdmin && $resellerId === null) {
            abort(403, 'Tidak terikat ke reseller manapun.');
        }

        $tenantId = $isAdmin ? auth()->user()->tenant_id : Reseller::findOrFail($resellerId)->tenant_id;

        $this->authorize('manage', new WhatsappSession(['reseller_id' => $isAdmin ? null : $resellerId]));

        $service->createSession($tenantId, $isAdmin ? null : $resellerId);

        session()->flash('status', 'Sesi dibuat — menunggu QR code muncul, halaman akan otomatis update.');
    }

    public function refreshQr(int $sessionId, WhatsappSessionService $service): void
    {
        $session = WhatsappSession::withoutGlobalScopes()->findOrFail($sessionId);
        $this->authorize('manage', $session);

        $service->refreshQrCode($session);

        session()->flash('status', 'Permintaan QR code baru dikirim — muat ulang beberapa detik lagi.');
    }

    /**
     * Tombol "Logout" — memanggil `client.Logout(ctx)` whatsmeow SUNGGUHAN
     * di sisi gateway (bukan sekadar wipe lokal), supaya entri "Perangkat
     * Tertaut" di HP ikut bersih di sisi WhatsApp sendiri.
     */
    public function logout(int $sessionId, WhatsappSessionService $service): void
    {
        $session = WhatsappSession::withoutGlobalScopes()->findOrFail($sessionId);
        $this->authorize('manage', $session);

        if ($service->logout($session)) {
            session()->flash('status', 'Logout berhasil — sesi siap dipasangkan ulang.');
        } else {
            session()->flash('status', 'Logout gagal — cek log, atau gateway sedang tidak dapat dihubungi.');
        }
    }

    public function togglePairingMode(int $sessionId): void
    {
        $this->pairingModeSessionId = $this->pairingModeSessionId === $sessionId ? null : $sessionId;
        $this->pairingPhoneNumber = '';
        $this->pairingCodeResult = null;
        $this->pairingCodeSessionId = null;
        $this->resetErrorBag('pairingPhoneNumber');
    }

    /**
     * "Pakai Kode Pairing" — alternatif native whatsmeow `PairPhone`
     * untuk menghubungkan sesi TANPA scan QR sama sekali. Lihat
     * `whatsapp-gateway/internal/session/manager.go::RequestPairingCode()`
     * untuk kenapa ini mewajibkan sesi yang belum terhubung (wipe + pairing
     * dari nol, sama seperti alur "refresh QR" pada sesi logged_out).
     */
    public function requestPairingCode(int $sessionId, WhatsappSessionService $service): void
    {
        $session = WhatsappSession::withoutGlobalScopes()->findOrFail($sessionId);
        $this->authorize('manage', $session);

        $this->validate([
            'pairingPhoneNumber' => 'required|string|min:9|max:15|regex:/^[0-9+]+$/',
        ]);

        $code = $service->requestPairingCode($session, $this->pairingPhoneNumber);

        if ($code === null) {
            session()->flash('status', 'Gagal meminta kode pairing — coba lagi, atau pakai Scan QR.');

            return;
        }

        $this->pairingCodeResult = $code;
        $this->pairingCodeSessionId = $sessionId;
        session()->flash('status', 'Kode pairing diterbitkan — masukkan di HP (Perangkat Tertaut > Tautkan dengan nomor telepon) sebelum kedaluwarsa.');
    }

    public function editTemplate(string $eventType): void
    {
        $resellerId = $this->resellerId;

        $template = WhatsappMessageTemplate::withoutGlobalScopes()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where('reseller_id', $resellerId)
            ->where('event_type', $eventType)
            ->first();

        $this->editingEventType = $eventType;
        $this->editingContent = $template?->content ?? '';
        $this->editingIsActive = $template?->is_active ?? true;
        $this->showTemplateForm = true;
    }

    public function saveTemplate(WhatsappTemplateService $service): void
    {
        $resellerId = $this->resellerId;

        $this->authorize('manage', new WhatsappMessageTemplate(['reseller_id' => $resellerId]));

        $this->validate(['editingContent' => 'required|string|min:3']);

        $service->upsert(
            auth()->user()->tenant_id,
            $resellerId,
            WhatsappEventType::from($this->editingEventType),
            $this->editingContent,
            $this->editingIsActive,
            auth()->id(),
        );

        $this->showTemplateForm = false;
        session()->flash('status', 'Template berhasil disimpan.');
    }

    public function resetTemplateToDefault(string $eventType, WhatsappTemplateService $service): void
    {
        if ($this->resellerId === null) {
            return;
        }

        $this->authorize('manage', new WhatsappMessageTemplate(['reseller_id' => $this->resellerId]));

        $service->resetToDefault(auth()->user()->tenant_id, $this->resellerId, WhatsappEventType::from($eventType));

        session()->flash('status', 'Template direset ke default ISP.');
    }

    public function retryMessage(int $logId, WhatsappGatewayService $service): void
    {
        $log = WhatsappMessageLog::withoutGlobalScopes()->findOrFail($logId);
        $this->authorize('retry', $log);

        $service->retry($log);

        session()->flash('status', 'Pesan diantrikan ulang.');
    }

    public function saveSettings(): void
    {
        $this->authorize('manage', WhatsappGatewaySettingsModel::class);

        $this->validate([
            'rateLimitDelayMin' => ['required', 'integer', 'min:1'],
            'rateLimitDelayMax' => ['required', 'integer', 'gte:rateLimitDelayMin'],
            'rateLimitBatchSize' => ['required', 'integer', 'min:1'],
            'rateLimitBatchPauseMin' => ['required', 'integer', 'min:1'],
            'rateLimitBatchPauseMax' => ['required', 'integer', 'gte:rateLimitBatchPauseMin'],
        ]);

        $times = array_values(array_filter(array_map('trim', explode(',', $this->dailyScheduleTimesRaw))));

        WhatsappGatewaySettingsModel::current()->update([
            'rate_limit_delay_min_seconds' => $this->rateLimitDelayMin,
            'rate_limit_delay_max_seconds' => $this->rateLimitDelayMax,
            'rate_limit_batch_size' => $this->rateLimitBatchSize,
            'rate_limit_batch_pause_min_minutes' => $this->rateLimitBatchPauseMin,
            'rate_limit_batch_pause_max_minutes' => $this->rateLimitBatchPauseMax,
            'daily_schedule_times' => $times,
        ]);

        session()->flash('status', 'Pengaturan rate limit disimpan.');
    }

    private function loadSettingsIntoForm(): void
    {
        $settings = WhatsappGatewaySettingsModel::current();

        $this->rateLimitDelayMin = $settings->rate_limit_delay_min_seconds;
        $this->rateLimitDelayMax = $settings->rate_limit_delay_max_seconds;
        $this->rateLimitBatchSize = $settings->rate_limit_batch_size;
        $this->rateLimitBatchPauseMin = $settings->rate_limit_batch_pause_min_minutes;
        $this->rateLimitBatchPauseMax = $settings->rate_limit_batch_pause_max_minutes;
        $this->dailyScheduleTimesRaw = implode(',', $settings->daily_schedule_times ?? ['08:00', '20:00']);
    }

    // ── Tab "Kirim Otomatis" (v0.28.5) — aksi; logika di service ─────────

    private function loadAutoSendIntoForm(): void
    {
        $s = WorkOrderDispatchSettings::forTenant(auth()->user()->tenant_id);

        $this->autoNotifyEnabled = (bool) $s->customer_auto_notify_enabled;
        $this->testModeEnabled = (bool) $s->invoice_pdf_test_mode_enabled;
        $this->testRecipient = $s->invoice_pdf_test_recipient;
        $this->invoiceAutoSendEnabled = (bool) $s->invoice_pdf_auto_send_enabled;
        $this->invoiceAutoSendFrom = $s->invoice_pdf_auto_send_from?->format('Y-m-d');
        $this->reminderDays = array_map('strval', DueReminderDays::sanitize($s->due_reminder_days));
        $this->dailyCap = (int) ($s->customer_auto_send_daily_cap ?? 80);
        $this->windowStart = substr((string) ($s->customer_auto_send_window_start ?? '08:00'), 0, 5);
        $this->windowEnd = substr((string) ($s->customer_auto_send_window_end ?? '20:00'), 0, 5);
        $this->breakerThreshold = (int) ($s->customer_auto_send_breaker_threshold ?? 10);
    }

    /** Kartu (a) — Notifikasi Otomatis ke Pelanggan (modal saat MENYALAKAN). */
    public function requestSaveAutoNotify(CustomerAutoSendSettingsService $service): void
    {
        $this->authorize('whatsapp_gateway.manage');

        $stored = (bool) $service->settings(auth()->user()->tenant_id)->customer_auto_notify_enabled;
        if ($this->autoNotifyEnabled && ! $stored) {
            $this->confirmAction = 'auto_notify';

            return;
        }

        $this->doSaveAutoNotify($service);
    }

    private function doSaveAutoNotify(CustomerAutoSendSettingsService $service): void
    {
        $this->authorize('whatsapp_gateway.manage');

        $service->saveCustomerAutoNotify(auth()->user()->tenant_id, $this->autoNotifyEnabled, auth()->id());
        $this->confirmAction = null;
        $this->loadAutoSendIntoForm();
        session()->flash('status', 'Pengaturan notifikasi otomatis ke pelanggan disimpan.');
    }

    /** Kartu (a) — Mode Uji (modal saat MEMATIKAN = go-live). */
    public function requestSaveTestMode(CustomerAutoSendSettingsService $service): void
    {
        $this->authorize('whatsapp_gateway.manage');

        // Menyalakan Mode Uji wajib nomor uji valid (tidak ada modal — ini lebih aman).
        if ($this->testModeEnabled) {
            $normalized = WhatsappPhone::normalize($this->testRecipient);
            if (strlen($normalized) < 10 || ! str_starts_with($normalized, '62')) {
                $this->addError('testRecipient', 'Mode Uji aktif wajib nomor uji yang valid (mis. 6287884374939).');

                return;
            }
        }

        $stored = (bool) $service->settings(auth()->user()->tenant_id)->invoice_pdf_test_mode_enabled;
        if (! $this->testModeEnabled && $stored) {
            $this->confirmAction = 'test_mode_off';

            return;
        }

        $this->doSaveTestMode($service);
    }

    private function doSaveTestMode(CustomerAutoSendSettingsService $service): void
    {
        $this->authorize('whatsapp_gateway.manage');

        $normalized = WhatsappPhone::normalize($this->testRecipient);
        $service->saveTestMode(
            auth()->user()->tenant_id,
            $this->testModeEnabled,
            $normalized === '' ? null : $normalized,
            auth()->id(),
        );
        $this->confirmAction = null;
        $this->loadAutoSendIntoForm();
        session()->flash('status', 'Pengaturan Mode Uji Kirim Invoice disimpan.');
    }

    /** Kartu (a) — Kirim Invoice Otomatis (modal saat MENYALAKAN). */
    public function requestSaveInvoiceAutoSend(CustomerAutoSendSettingsService $service): void
    {
        $this->authorize('whatsapp_gateway.manage');

        $this->validate(
            ['invoiceAutoSendFrom' => ['nullable', 'date']],
            [],
            ['invoiceAutoSendFrom' => 'tanggal mulai'],
        );

        $stored = (bool) $service->settings(auth()->user()->tenant_id)->invoice_pdf_auto_send_enabled;
        if ($this->invoiceAutoSendEnabled && ! $stored) {
            $this->confirmAction = 'invoice_auto_send';

            return;
        }

        $this->doSaveInvoiceAutoSend($service);
    }

    private function doSaveInvoiceAutoSend(CustomerAutoSendSettingsService $service): void
    {
        $this->authorize('whatsapp_gateway.manage');

        $service->saveInvoiceAutoSend(
            auth()->user()->tenant_id,
            $this->invoiceAutoSendEnabled,
            $this->invoiceAutoSendFrom ?: null,
            auth()->id(),
        );
        $this->confirmAction = null;
        $this->loadAutoSendIntoForm();
        session()->flash('status', 'Pengaturan Kirim Invoice Otomatis disimpan.');
    }

    public function confirmPending(CustomerAutoSendSettingsService $service): void
    {
        match ($this->confirmAction) {
            'auto_notify' => $this->doSaveAutoNotify($service),
            'test_mode_off' => $this->doSaveTestMode($service),
            'invoice_auto_send' => $this->doSaveInvoiceAutoSend($service),
            default => $this->cancelConfirm(),
        };
    }

    public function cancelConfirm(): void
    {
        $this->confirmAction = null;
    }

    /** Kartu (b) — jadwal hari pengingat jatuh tempo (H-0..H-7), minimal 1. */
    public function saveReminderDays(CustomerAutoSendSettingsService $service): void
    {
        $this->authorize('whatsapp_gateway.manage');

        $this->validate([
            'reminderDays' => ['array', 'min:1'],
            'reminderDays.*' => ['integer', 'min:'.DueReminderDays::MIN, 'max:'.DueReminderDays::MAX],
        ], [
            'reminderDays.min' => 'Pilih minimal 1 hari pengingat.',
            'reminderDays.required' => 'Pilih minimal 1 hari pengingat.',
        ], ['reminderDays' => 'hari pengingat']);

        $days = DueReminderDays::sanitize(array_map('intval', $this->reminderDays));
        $service->saveReminderDays(auth()->user()->tenant_id, $days, auth()->id());

        $this->loadAutoSendIntoForm();
        session()->flash('status', 'Jadwal hari pengingat disimpan.');
    }

    /** Kartu (c) — plafon harian, jendela jam, ambang pemutus. */
    public function saveVolumeControls(CustomerAutoSendSettingsService $service): void
    {
        $this->authorize('whatsapp_gateway.manage');

        $this->validate([
            'dailyCap' => ['required', 'integer', 'min:1', 'max:1000'],
            'windowStart' => ['required', 'date_format:H:i'],
            'windowEnd' => ['required', 'date_format:H:i', 'after:windowStart'],
            'breakerThreshold' => ['required', 'integer', 'min:1', 'max:100'],
        ], [
            'windowEnd.after' => 'Jam akhir harus lebih besar dari jam awal.',
        ], [
            'dailyCap' => 'plafon harian',
            'windowStart' => 'jam awal',
            'windowEnd' => 'jam akhir',
            'breakerThreshold' => 'ambang pemutus',
        ]);

        $service->saveVolumeControls(
            auth()->user()->tenant_id,
            $this->dailyCap,
            $this->windowStart,
            $this->windowEnd,
            $this->breakerThreshold,
            auth()->id(),
        );

        $this->loadAutoSendIntoForm();
        session()->flash('status', 'Kontrol volume (plafon/jendela/pemutus) disimpan.');
    }

    /** Kartu (d) — tutup pemutus (mesin sama whatsapp:auto-send-resume). */
    public function resumeBreaker(CustomerAutoSendSettingsService $service): void
    {
        $this->authorize('whatsapp_gateway.manage');

        $resumed = $service->resumeAll(auth()->user()->tenant_id, auth()->id());
        session()->flash('status', "Pemutus dilanjutkan untuk {$resumed} sesi.");
    }

    public function render()
    {
        $isAdmin = $this->isAdmin();

        $mySession = $this->resellerId !== null
            ? WhatsappSession::where('reseller_id', $this->resellerId)->first()
            : null;

        // Shown in its own dedicated block (admin manages this one
        // directly); $resellerSessions below is read-only status/overview
        // for admin — refresh/create for those belongs to the reseller
        // themselves (WhatsappSessionPolicy::manage()).
        $directSession = $isAdmin
            ? WhatsappSession::withoutGlobalScopes()->whereNull('reseller_id')->first()
            : null;

        $resellerSessions = $isAdmin
            ? WhatsappSession::with('reseller')->whereNotNull('reseller_id')->orderBy('reseller_id')->get()
            : collect();

        $resellerIdForTemplates = $this->resellerId;

        // v0.22.4 — StaffInitialPasswordValue SENGAJA dikeluarkan dari daftar
        // ini: event type itu tidak pernah melalui WhatsappTemplateService
        // sama sekali (rendered_content = password mentah, hardcode di
        // StaffService — lihat docblock WhatsappEventType::
        // StaffInitialPasswordValue) — menampilkannya di sini akan
        // membiarkan admin mengedit "template" yang faktanya tidak pernah
        // dibaca kode, membingungkan tanpa guna.
        $templates = collect(WhatsappEventType::cases())
            ->reject(fn (WhatsappEventType $eventType) => $eventType === WhatsappEventType::StaffInitialPasswordValue)
            ->map(function (WhatsappEventType $eventType) use ($resellerIdForTemplates) {
                $own = WhatsappMessageTemplate::withoutGlobalScopes()
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->where('reseller_id', $resellerIdForTemplates)
                    ->where('event_type', $eventType->value)
                    ->first();

                $default = $resellerIdForTemplates !== null
                    ? WhatsappMessageTemplate::withoutGlobalScopes()
                        ->where('tenant_id', auth()->user()->tenant_id)
                        ->whereNull('reseller_id')
                        ->where('event_type', $eventType->value)
                        ->first()
                    : null;

                return (object) [
                    'event_type' => $eventType,
                    'own' => $own,
                    'default' => $default,
                    'effective_content' => $own?->content ?? $default?->content ?? '—',
                    'is_override' => $own !== null,
                ];
            });

        $logs = WhatsappMessageLog::query()
            ->knownEventType()
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($isAdmin && $this->resellerFilter !== null, fn ($q) => $q->where('reseller_id', $this->resellerFilter))
            ->latest()
            ->paginate(15);

        $resellers = $isAdmin ? Reseller::orderBy('name')->get() : collect();

        // "Pesan Masuk" (v0.13.1, diperluas) — sekarang VISIBLE untuk
        // reseller juga (bukan admin-only lagi), tapi scoping ditegakkan
        // di QUERY-nya, bukan cuma UI (defense-in-depth):
        // - Admin: lihat semua (termasuk reseller_id null / Direct),
        //   filter opsional via $resellerFilter (reuse PERSIS pola Pesan
        //   Keluar di atas — properti yang sama).
        // - Reseller (context resolved dari reseller_users membership
        //   aktif, App\Support\ResellerContext): WHERE reseller_id = milik
        //   sendiri secara eksplisit — tidak pernah bergantung pada scope
        //   Eloquent global (model ini memang tidak punya satu pun, lihat
        //   docblock model), supaya tidak ada jalan bocor lewat properti
        //   Livewire yang dipaksa dari luar (mis. force-set $tab).
        // - Bukan admin & bukan reseller (mustahil tercapai — viewAny()
        //   WhatsappSessionPolicy sudah mensyaratkan salah satu): null,
        //   tab tidak dirender di blade.
        $canSeeIncoming = $isAdmin || $this->resellerId !== null;

        $incomingMessages = match (true) {
            $isAdmin => WhatsappIncomingMessage::query()
                ->when($this->resellerFilter !== null, fn ($q) => $q->where('reseller_id', $this->resellerFilter))
                ->latest('received_at')
                ->paginate(15, ['*'], 'incoming-page'),
            $this->resellerId !== null => WhatsappIncomingMessage::query()
                ->where('reseller_id', $this->resellerId)
                ->latest('received_at')
                ->paginate(15, ['*'], 'incoming-page'),
            default => null,
        };

        $resellersById = $resellers->keyBy('id');
        if (! $isAdmin && $this->resellerId !== null) {
            $resellersById->put($this->resellerId, Reseller::find($this->resellerId));
        }

        // Tab "Kirim Otomatis" (v0.28.5) — admin-only, per tenant. Data
        // indikator/jejak/pemutus dari service (logika read-only di service).
        $autoSend = null;
        if ($isAdmin) {
            $service = app(CustomerAutoSendSettingsService::class);
            $tenantId = auth()->user()->tenant_id;
            $autoSend = [
                'indicators' => $service->indicators($tenantId),
                'decisions' => $service->recentDecisions($tenantId, 20),
                'breaker' => $service->breakerStatus($tenantId),
                'audit' => $service->recentAuditLogs($tenantId, 10),
                'result_labels' => CustomerAutoSendSettingsService::resultLabels(),
                'batch' => [
                    'size' => (int) WhatsappGatewaySettingsModel::current()->rate_limit_batch_size,
                    'pause_min' => (int) WhatsappGatewaySettingsModel::current()->rate_limit_batch_pause_min_minutes,
                    'pause_max' => (int) WhatsappGatewaySettingsModel::current()->rate_limit_batch_pause_max_minutes,
                ],
            ];
        }

        return view('livewire.whatsapp.whatsapp-gateway-index', [
            'isAdmin' => $isAdmin,
            'mySession' => $mySession,
            'directSession' => $directSession,
            'resellerSessions' => $resellerSessions,
            'templates' => $templates,
            'logs' => $logs,
            'resellers' => $resellers,
            'canSeeIncoming' => $canSeeIncoming,
            'incomingMessages' => $incomingMessages,
            'resellersById' => $resellersById,
            'autoSend' => $autoSend,
        ]);
    }
}
