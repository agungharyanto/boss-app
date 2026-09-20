{{--
    Sprint "whatsapp-gateway-reliability" — panel "belum terhubung" untuk
    satu sesi WhatsApp: QR (default) + tombol "Refresh QR", ATAU alternatif
    native (Baileys/whatsmeow) "Kode Pairing" (requestPairingCode/PairPhone, TANPA scan QR sama
    sekali). Dipakai identik untuk sesi reseller (mySession) maupun sesi
    direct/ISP (directSession) — parameter `$session` + `$labelPrefix`.
--}}
@php
    // Redesign — overlay dipicu sinyal GENUINE dari whatsmeow
    // (whatsapp_sessions.qr_expired_at, diisi webhook saat
    // drainQRChannel()'s "timeout" non-pertama, lihat
    // whatsapp-gateway/internal/session/manager.go), BUKAN LAGI tebakan
    // waktu (counter 3s×5x — dihapus total). Di-reset null otomatis di
    // sisi Laravel begitu qr_code_data BARU genuinely diterima
    // (WhatsappSessionService::applyStatus()).
    $qrExpired = $session->qr_expired_at !== null;
@endphp
@if ($session->qr_code_data && $pairingModeSessionId !== $session->id)
    <div class="relative w-48 h-48">
        <img src="{{ $session->qr_code_data }}" alt="QR WhatsApp" class="w-48 h-48">
        @if ($qrExpired)
            {{-- Overlay GELAP — QR itu sendiri pola hitam-putih padat, overlay
                 terang/samar menyatu dengan pola itu (dikonfirmasi screenshot
                 Agung di iterasi sebelumnya). Ikon PUTIH di atas overlay gelap
                 = kontras maksimal terhadap overlay MAUPUN QR di baliknya. --}}
            <button
                type="button"
                wire:click="refreshQr({{ $session->id }})"
                title="QR sudah kedaluwarsa — klik untuk muat ulang"
                class="absolute inset-0 w-48 h-48 flex items-center justify-center bg-gray-900/70 hover:bg-gray-900/80 rounded-md"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </button>
        @endif
    </div>
    <p class="text-xs text-gray-400">
        @if ($qrExpired)
            QR ini sudah kedaluwarsa (tidak lagi bisa discan) — klik ikon di atas QR (atau tombol di bawah) untuk muat ulang.
        @else
            Scan QR ini pakai WhatsApp di HP {{ $labelPrefix }}. Halaman ini otomatis update setiap 3 detik.
        @endif
    </p>
@elseif ($pairingModeSessionId !== $session->id)
    <p class="text-sm text-gray-400">Menunggu QR code dari server...</p>
@endif

<div class="flex flex-wrap items-center gap-2">
    @if ($pairingModeSessionId !== $session->id)
        <button wire:click="refreshQr({{ $session->id }})" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
            Refresh QR Code
        </button>
    @endif
    <button wire:click="togglePairingMode({{ $session->id }})" type="button"
        class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 text-sm text-gray-700">
        {{ $pairingModeSessionId === $session->id ? 'Batal, pakai Scan QR' : 'Pakai Kode Pairing' }}
    </button>
</div>

@if ($pairingModeSessionId === $session->id)
    <div class="mt-2 p-3 bg-gray-50 border border-gray-200 rounded-md space-y-2">
        <p class="text-xs text-gray-500">
            Tanpa scan QR — masukkan nomor HP WhatsApp {{ $labelPrefix }} (format 628xxxxxxxxxx), lalu buka
            <strong>WhatsApp di HP &rarr; Perangkat Tertaut &rarr; Tautkan dengan nomor telepon</strong>
            dan masukkan kode yang muncul di sini.
        </p>
        <div class="flex flex-wrap items-center gap-2">
            <input type="text" wire:model="pairingPhoneNumber" placeholder="6281234567890"
                class="rounded-md border-gray-300 shadow-sm text-sm">
            <button wire:click="requestPairingCode({{ $session->id }})" wire:loading.attr="disabled"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                Minta Kode Pairing
            </button>
        </div>
        @error('pairingPhoneNumber') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

        @if ($pairingCodeSessionId === $session->id && $pairingCodeResult)
            <div class="mt-2 p-4 bg-white border-2 border-primary rounded-md text-center">
                <p class="text-xs text-gray-500 mb-1">Kode Pairing Anda</p>
                <p class="text-3xl font-mono font-bold tracking-widest text-gray-800">{{ $pairingCodeResult }}</p>
                <p class="text-xs text-gray-400 mt-1">Masukkan di HP dalam beberapa menit sebelum kedaluwarsa.</p>
            </div>
        @endif
    </div>
@endif
