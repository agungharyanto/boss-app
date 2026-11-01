<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Aktivasi Pelanggan - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-100 min-h-screen py-6 px-4">
        {{-- v0.13.4.2.2 — Form aktivasi pelanggan via signed-link. Public-facing,
             TANPA login (signature = otorisasi). Blade biasa + Alpine
             object-literal (BUKAN Livewire, BUKAN x-data factory). POST ke
             url()->full() supaya signature ikut terbawa. --}}
        <div class="max-w-2xl mx-auto bg-white rounded-md shadow p-6">
            <h1 class="text-xl font-semibold text-gray-800 mb-1">Aktivasi Pelanggan</h1>
            <p class="text-sm text-gray-500 mb-4">{{ config('app.name') }}</p>

            <div class="border border-gray-200 rounded-md p-4 mb-6 bg-gray-50">
                <h2 class="text-sm font-semibold text-gray-700 mb-2">Ringkasan Work Order</h2>
                <dl class="text-sm text-gray-700 space-y-1">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Pelanggan</dt>
                        <dd class="font-medium">{{ $customer?->name ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Alamat</dt>
                        <dd class="font-medium text-right">{{ $customer?->address ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Paket</dt>
                        <dd class="font-medium">{{ $workOrder->customer?->pppPackage?->name ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Teknisi</dt>
                        <dd class="font-medium">{{ $claimingTechnician->name }}</dd>
                    </div>
                </dl>
            </div>

            <div class="border border-gray-200 rounded-md p-4 mb-6">
                <h2 class="text-sm font-semibold text-gray-700 mb-2">Modem yang Dipakai</h2>
                @if ($firstModem === null)
                    <p class="text-sm text-red-600">Belum ada modem tercatat dari klaim — aktivasi tidak bisa dilanjutkan.</p>
                @else
                    <dl class="text-sm text-gray-700 space-y-1">
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Serial Number</dt>
                            <dd class="font-medium">{{ $firstModem->serial_number }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">MAC Address</dt>
                            <dd class="font-medium">{{ $firstModem->mac_address }}</dd>
                        </div>
                    </dl>
                    @if ($modemUnitCount > 1)
                        <p class="text-xs text-amber-600 mt-2">
                            Ada {{ $modemUnitCount }} unit modem tercatat — aktivasi memakai unit PERTAMA di atas.
                        </p>
                    @endif
                @endif
                @if ($pppoeUsername !== null)
                    <p class="text-xs text-gray-500 mt-3">Username PPPoE yang akan dibuat: <span class="font-mono text-gray-700">{{ $pppoeUsername }}</span> (password dibuat otomatis oleh sistem).</p>
                @else
                    <p class="text-xs text-red-600 mt-3">Pelanggan belum punya CID — username PPPoE tidak bisa dibentuk.</p>
                @endif
            </div>

            @isset($submitError)
                <div class="border border-red-200 bg-red-50 text-red-800 rounded-md p-3 mb-4 text-sm">{{ $submitError }}</div>
            @endisset

            @if ($errors->any())
                <div class="border border-red-200 bg-red-50 text-red-800 rounded-md p-3 mb-4 text-sm">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ url()->full() }}" x-data="{ showPw: false }">
                @csrf

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nama WiFi (SSID)</label>
                    <input type="text" name="ssid" value="{{ old('ssid', $ssidSuggestion) }}" maxlength="32" required
                        class="w-full border-gray-300 rounded-md text-sm">
                    <p class="text-xs text-gray-500 mt-1">Saran terisi otomatis — boleh diubah.</p>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password WiFi</label>
                    <div class="flex gap-2">
                        <input :type="showPw ? 'text' : 'password'" name="wifi_password" minlength="8" maxlength="63" required
                            class="flex-1 border-gray-300 rounded-md text-sm" placeholder="Minimal 8 karakter">
                        <button type="button" @click="showPw = !showPw"
                            class="border border-gray-300 rounded-md px-3 text-xs text-gray-600 hover:bg-gray-50"
                            x-text="showPw ? 'Sembunyikan' : 'Lihat'"></button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Minimal 8 karakter.</p>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ketik Nama Pelanggan (konfirmasi)</label>
                    <input type="text" name="customer_name" value="{{ old('customer_name') }}" required
                        autocomplete="off" class="w-full border-gray-300 rounded-md text-sm"
                        placeholder="Ketik ulang nama pelanggan persis">
                    <p class="text-xs text-gray-500 mt-1">Harus cocok dengan nama pelanggan pada Work Order ini.</p>
                </div>

                <button type="submit" @if ($firstModem === null || $pppoeUsername === null) disabled @endif
                    class="w-full bg-primary text-white rounded-md py-2 text-sm font-semibold hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed">
                    Aktifkan Pelanggan
                </button>
            </form>
        </div>

        @livewireScripts
    </body>
</html>
