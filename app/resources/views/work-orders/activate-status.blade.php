@extends('layouts.wo-public')

@section('title', 'Status Aktivasi')

@push('head')
    {{-- v0.13.4.2.2 — penyegaran otomatis ringan sampai SEMUA tahap selesai,
         lalu berhenti (tidak refresh lagi). url()->full() mempertahankan
         signature link. --}}
    @unless ($activation['all_done'])
        <meta http-equiv="refresh" content="6">
    @endunless
@endpush

@section('content')
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

    @if (($closeUrl ?? null) !== null)
        <div class="mt-6 border-t border-gray-200 pt-4">
            <a href="{{ $closeUrl }}"
                class="block w-full text-center bg-primary text-white rounded-md py-2 text-sm font-semibold hover:opacity-90">
                Tutup Work Order
            </a>
            <p class="text-xs text-gray-500 mt-2 text-center">
                Lanjut ke form penutupan: upload 6 foto, konfirmasi nama pelanggan, dan tanda tangan pelanggan.
                Penutupan hanya bisa setelah ONU online &amp; WiFi terkirim.
            </p>
        </div>
    @endif
@endsection
