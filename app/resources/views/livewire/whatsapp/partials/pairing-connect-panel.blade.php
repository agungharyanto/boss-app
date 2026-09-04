{{--
    Sprint "whatsapp-gateway-reliability" — panel "belum terhubung" untuk
    satu sesi WhatsApp: QR (default) + tombol "Refresh QR", ATAU alternatif
    native Baileys "Kode Pairing" (requestPairingCode, TANPA scan QR sama
    sekali). Dipakai identik untuk sesi reseller (mySession) maupun sesi
    direct/ISP (directSession) — parameter `$session` + `$labelPrefix`.
--}}
@if ($session->qr_code_data && $pairingModeSessionId !== $session->id)
    <img src="{{ $session->qr_code_data }}" alt="QR WhatsApp" class="w-48 h-48">
    <p class="text-xs text-gray-400">Scan QR ini pakai WhatsApp di HP {{ $labelPrefix }}. Halaman ini otomatis update setiap 3 detik.</p>
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
