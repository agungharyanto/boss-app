{{-- v0.28.5 — Tab "Kirim Otomatis" (admin, per tenant). Logika di
     CustomerAutoSendSettingsService; komponen WhatsappGatewayIndex memanggil
     service. --}}
@php
    $ind = $autoSend['indicators'];
    $brk = $autoSend['breaker'];
    $batch = $autoSend['batch'];
    $resultLabels = $autoSend['result_labels'];
@endphp

<div class="space-y-6">
    <p class="text-sm text-gray-500">
        Pengaturan pesan WhatsApp OTOMATIS ke PELANGGAN (pengingat jatuh tempo, invoice terbit,
        pelanggan terdaftar, pengingat suspend). TIDAK memengaruhi OTP referrer, notifikasi teknisi/WO,
        grup WO, atau balasan 2 arah.
    </p>

    {{-- ── (a) Notifikasi Otomatis ke Pelanggan ─────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold text-gray-800">Notifikasi Otomatis ke Pelanggan</h2>
            @if ($autoNotifyEnabled)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">AKTIF</span>
            @else
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">MATI</span>
            @endif
        </div>
        <p class="text-xs text-gray-500 mb-4">Saklar utama semua kiriman otomatis ke pelanggan. MATI = tidak ada pesan otomatis pelanggan sama sekali.</p>
        <label class="flex items-center gap-2 text-sm text-gray-700 mb-4">
            <input type="checkbox" wire:model="autoNotifyEnabled" class="rounded border-gray-300">
            <span>Kirim notifikasi otomatis ke pelanggan</span>
        </label>
        <button type="button" wire:click="requestSaveAutoNotify" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">Simpan</button>
    </div>

    {{-- Mode Uji --}}
    <div class="bg-white border border-gray-200 rounded-md p-6">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold text-gray-800">Mode Uji Kirim Invoice</h2>
            @if ($testModeEnabled)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">AKTIF</span>
            @else
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">MATI</span>
            @endif
        </div>
        <p class="text-xs text-gray-500 mb-4">AKTIF = semua kiriman invoice dialihkan ke NOMOR UJI (pelanggan nyata tidak menerima apa pun). Matikan untuk mengirim ke pelanggan sungguhan.</p>
        <label class="flex items-center gap-2 text-sm text-gray-700 mb-3">
            <input type="checkbox" wire:model="testModeEnabled" class="rounded border-gray-300">
            <span>Aktifkan Mode Uji (alihkan ke nomor uji)</span>
        </label>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nomor WhatsApp Uji</label>
        <input type="text" wire:model="testRecipient" placeholder="6287884374939" class="block w-full sm:w-72 rounded-md border-gray-300 shadow-sm text-sm">
        @error('testRecipient') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
        <p class="text-xs text-gray-400 mt-1 mb-4">Otomatis dinormalisasi ke format 62… (mis. 087884374939 → 6287884374939).</p>
        <button type="button" wire:click="requestSaveTestMode" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">Simpan</button>
    </div>

    {{-- Kirim Invoice Otomatis --}}
    <div class="bg-white border border-gray-200 rounded-md p-6">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold text-gray-800">Kirim Invoice Otomatis</h2>
            @if ($invoiceAutoSendEnabled)
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">AKTIF</span>
            @else
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">MATI</span>
            @endif
        </div>
        <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-xs text-amber-800 mb-4 space-y-1">
            <p>• Hanya invoice yang terbit SETELAH batas mulai (bukan backlog lama).</p>
            <p>• Butuh saklar "Notifikasi Otomatis ke Pelanggan" di atas juga AKTIF.</p>
            <p>• Mengikuti plafon harian + jendela jam di bawah. BERHENTI TOTAL saat Mode Uji AKTIF.</p>
            <p>• Pastikan pengingat/tagihan MixRadius sudah DIMATIKAN dulu agar pelanggan tidak dapat tagihan ganda.</p>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700 mb-4">
            <input type="checkbox" wire:model="invoiceAutoSendEnabled" class="rounded border-gray-300">
            <span>Aktifkan kirim PDF invoice otomatis</span>
        </label>
        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Mulai dari tanggal</label>
            <input type="date" wire:model="invoiceAutoSendFrom" class="block w-full sm:w-72 rounded-md border-gray-300 shadow-sm text-sm">
            @error('invoiceAutoSendFrom') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            <p class="text-xs text-gray-400 mt-1">Kosongkan = mulai dari saat saklar dinyalakan.</p>
            @if ($invoiceAutoSendFrom && $invoiceAutoSendFrom < now()->toDateString())
                <p class="text-xs text-amber-700 mt-1">Peringatan: invoice Pending yang sudah terbit sejak tanggal itu akan dikirim bertahap sesuai plafon.</p>
            @endif
        </div>
        <button type="button" wire:click="requestSaveInvoiceAutoSend" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">Simpan</button>
    </div>

    {{-- ── (b) Jadwal hari pengingat jatuh tempo ────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">Jadwal Hari Pengingat Jatuh Tempo</h2>
        <p class="text-xs text-gray-500 mb-4">Pilih pada H berapa sebelum jatuh tempo pengingat dikirim (minimal 1). Default H-5 dan H-0.</p>
        <div class="flex flex-wrap gap-3 mb-2">
            @for ($h = 7; $h >= 0; $h--)
                <label class="flex items-center gap-1.5 text-sm text-gray-700">
                    <input type="checkbox" wire:model="reminderDays" value="{{ $h }}" class="rounded border-gray-300">
                    <span>H-{{ $h }}@if ($h === 0) <span class="text-gray-400">(hari jatuh tempo)</span>@endif</span>
                </label>
            @endfor
        </div>
        @if (in_array('7', $reminderDays) || in_array(7, $reminderDays))
            <p class="text-xs text-amber-700 mb-2">⚠️ H-7 bersamaan dengan hari terbit invoice — pelanggan bisa menerima 2 pesan.</p>
        @endif
        @error('reminderDays') <p class="text-sm text-red-600 mb-2">{{ $message }}</p> @enderror
        <button type="button" wire:click="saveReminderDays" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">Simpan</button>
    </div>

    {{-- ── (c) Kontrol volume ───────────────────────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">Plafon, Jendela Jam & Pemutus</h2>
        <p class="text-xs text-gray-500 mb-4">Berlaku GABUNGAN untuk semua jalur otomatis pelanggan (per tenant).</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Plafon harian (pesan/hari)</label>
                <input type="number" min="1" max="1000" wire:model="dailyCap" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                @error('dailyCap') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Ambang pemutus (N kegagalan beruntun)</label>
                <input type="number" min="1" max="100" wire:model="breakerThreshold" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                @error('breakerThreshold') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Jendela jam — awal (WIB)</label>
                <input type="time" wire:model="windowStart" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                @error('windowStart') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Jendela jam — akhir (WIB)</label>
                <input type="time" wire:model="windowEnd" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                @error('windowEnd') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
        <button type="button" wire:click="saveVolumeControls" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">Simpan</button>
    </div>

    {{-- ── (d) Status pemutus + Lanjutkan ───────────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-2">Status Pemutus</h2>
        @if ($brk['any_open'])
            <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700 mb-3 space-y-1">
                <p class="font-medium">Terbuka — kirim otomatis dihentikan.</p>
                @foreach ($brk['open'] as $b)
                    <p class="text-xs">Sesi <span class="font-medium">{{ $b->session_key }}</span>: {{ $b->open_reason ?? 'N kegagalan beruntun' }} — sejak {{ optional($b->opened_at)->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '—' }}</p>
                @endforeach
            </div>
            <button type="button" wire:click="resumeBreaker" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">Lanjutkan</button>
        @else
            <p class="text-sm text-green-700">Tertutup (normal) — kirim otomatis berjalan.</p>
        @endif
    </div>

    {{-- ── (e) Pengaturan batch (read-only) ─────────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-1">Jeda Batch (global)</h2>
        <p class="text-xs text-gray-500 mb-3">Ukuran batch & jeda antar-batch bersifat GLOBAL (bukan per tenant) dan diatur di tab Rate Limit — ditampilkan di sini untuk referensi saja.</p>
        <div class="text-sm text-gray-700 space-y-0.5 mb-3">
            <p>Ukuran batch: <span class="font-medium">{{ $batch['size'] }}</span> pesan</p>
            <p>Jeda antar-batch: <span class="font-medium">{{ $batch['pause_min'] }}–{{ $batch['pause_max'] }}</span> menit</p>
        </div>
        <button type="button" wire:click="setTab('settings')" class="text-sm text-primary hover:underline">Ubah di tab Rate Limit →</button>
    </div>

    {{-- ── (f) Indikator hari ini (WIB) ─────────────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">Indikator Hari Ini (WIB)</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
            <div class="rounded-md bg-gray-50 border border-gray-200 p-3">
                <p class="text-xs text-gray-500">Ter-antre / Plafon</p>
                <p class="text-lg font-semibold text-gray-800">{{ $ind['queued_today'] }} / {{ $ind['cap'] }}</p>
            </div>
            <div class="rounded-md bg-gray-50 border border-gray-200 p-3">
                <p class="text-xs text-gray-500">Deferral pending</p>
                <p class="text-lg font-semibold text-gray-800">{{ $ind['deferrals_pending'] }}</p>
            </div>
            <div class="rounded-md bg-gray-50 border border-gray-200 p-3 col-span-2">
                <p class="text-xs text-gray-500">Slot kursor berikutnya</p>
                <p class="text-sm font-semibold text-gray-800">{{ $ind['next_cursor_slot'] ?? '—' }}</p>
            </div>
        </div>

        <h3 class="text-sm font-medium text-gray-700 mb-1">Keputusan hari ini per hasil</h3>
        @if (count($ind['decision_counts']) === 0)
            <p class="text-xs text-gray-400 mb-4">Belum ada keputusan hari ini.</p>
        @else
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach ($ind['decision_counts'] as $result => $count)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-700">
                        {{ $resultLabels[$result] ?? $result }}: <span class="font-semibold">{{ $count }}</span>
                    </span>
                @endforeach
            </div>
        @endif

        <h3 class="text-sm font-medium text-gray-700 mb-1">20 keputusan terakhir</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-left text-gray-500 border-b border-gray-200">
                        <th class="py-1.5 pr-3">Waktu</th>
                        <th class="py-1.5 pr-3">Jalur</th>
                        <th class="py-1.5 pr-3">Pelanggan</th>
                        <th class="py-1.5 pr-3">Nomor Invoice</th>
                        <th class="py-1.5 pr-3">Hasil</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($autoSend['decisions'] as $d)
                        <tr class="border-b border-gray-100">
                            <td class="py-1.5 pr-3 whitespace-nowrap">{{ $d->created_at?->timezone('Asia/Jakarta')->format('d/m H:i:s') }}</td>
                            <td class="py-1.5 pr-3">{{ $d->path }}</td>
                            <td class="py-1.5 pr-3">{{ $d->customer?->name ?? '—' }}</td>
                            <td class="py-1.5 pr-3">{{ $d->invoice?->invoice_number ?? '—' }}</td>
                            <td class="py-1.5 pr-3">{{ $d->result->label() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-2 text-gray-400">Belum ada keputusan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Jejak perubahan pengaturan (10 terakhir) ─────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-md p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-3">10 Perubahan Pengaturan Terakhir</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-left text-gray-500 border-b border-gray-200">
                        <th class="py-1.5 pr-3">Waktu</th>
                        <th class="py-1.5 pr-3">Field</th>
                        <th class="py-1.5 pr-3">Dari → Ke</th>
                        <th class="py-1.5 pr-3">Oleh</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($autoSend['audit'] as $log)
                        <tr class="border-b border-gray-100">
                            <td class="py-1.5 pr-3 whitespace-nowrap">{{ $log->created_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
                            <td class="py-1.5 pr-3">{{ \App\Services\Whatsapp\CustomerAutoSendSettingsService::fieldLabel($log->field) }}</td>
                            <td class="py-1.5 pr-3">{{ $log->before ?? '∅' }} → {{ $log->after ?? '∅' }}</td>
                            <td class="py-1.5 pr-3">{{ $log->user?->name ?? 'sistem' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-gray-400">Belum ada perubahan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ── Modal konfirmasi aksi berisiko (item 3) ──────────────────────────── --}}
@if ($confirmAction)
    <div class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" wire:click.self="cancelConfirm">
        <div class="bg-white rounded-md shadow-lg max-w-md w-full mx-4 p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-2">Konfirmasi</h3>
            <p class="text-sm text-gray-600 mb-4">
                @if ($confirmAction === 'auto_notify')
                    Menyalakan <span class="font-medium">Notifikasi Otomatis ke Pelanggan</span> akan membuat sistem mulai mengirim pesan WhatsApp OTOMATIS ke pelanggan nyata (sesuai jalur yang aktif). Lanjutkan?
                @elseif ($confirmAction === 'test_mode_off')
                    Mematikan <span class="font-medium">Mode Uji</span> berarti kiriman invoice tidak lagi dialihkan ke nomor uji — <span class="font-medium text-red-600">pelanggan nyata akan menerima pesan</span>. Lanjutkan?
                @elseif ($confirmAction === 'invoice_auto_send')
                    Menyalakan <span class="font-medium">Kirim Invoice Otomatis</span> akan menjadwalkan pengiriman PDF invoice ke pelanggan nyata (sesuai plafon & jendela jam). Pastikan MixRadius sudah dimatikan. Lanjutkan?
                @endif
            </p>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="cancelConfirm" class="px-4 py-2 text-sm text-gray-600">Batal</button>
                <button type="button" wire:click="confirmPending" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">Ya, lanjutkan</button>
            </div>
        </div>
    </div>
@endif
