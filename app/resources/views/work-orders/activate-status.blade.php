<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        {{-- v0.13.4.2.2 — penyegaran otomatis ringan sampai SEMUA tahap selesai,
             lalu berhenti (tidak refresh lagi). url()->full() mempertahankan
             signature link. --}}
        @unless ($activation['all_done'])
            <meta http-equiv="refresh" content="6">
        @endunless
        <title>Status Aktivasi - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-100 min-h-screen py-6 px-4">
        <div class="max-w-2xl mx-auto bg-white rounded-md shadow p-6">
            <h1 class="text-xl font-semibold text-gray-800 mb-1">Status Aktivasi</h1>
            <p class="text-sm text-gray-500 mb-4">WO #{{ $activation['work_order_id'] }} — {{ $workOrder->customer?->name ?? '-' }}</p>

            @if ($activation['all_done'])
                <div class="border border-green-200 bg-green-50 text-green-800 rounded-md p-4 text-sm mb-4">
                    Aktivasi selesai — semua tahap sukses. WiFi sudah terkirim ke perangkat pelanggan.
                </div>
            @else
                <div class="border border-amber-200 bg-amber-50 text-amber-800 rounded-md p-4 text-sm mb-4">
                    Aktivasi sedang diproses. Halaman ini menyegar otomatis setiap beberapa detik. Beberapa tahap (mis. ONU online &amp; WiFi) bisa butuh waktu sampai perangkat melapor ke sistem.
                </div>
            @endif

            <ol class="space-y-2 mb-4">
                @foreach ($activation['stages'] as $stage)
                    <li class="flex items-center gap-3 text-sm">
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full text-xs font-bold
                            {{ $stage['done'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-400' }}">
                            {{ $stage['done'] ? '✓' : '•' }}
                        </span>
                        <span class="{{ $stage['done'] ? 'text-gray-800' : 'text-gray-500' }}">{{ $stage['label'] }}</span>
                    </li>
                @endforeach
            </ol>

            @if ($activation['pppoe_username'] !== null)
                <p class="text-xs text-gray-500">Username PPPoE: <span class="font-mono text-gray-700">{{ $activation['pppoe_username'] }}</span></p>
            @endif
        </div>

        @livewireScripts
    </body>
</html>
