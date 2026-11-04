{{-- v0.13.4.5 — Kartu status seragam halaman publik WO: ikon + judul status,
     kalimat detail, identitas WO ringkas (nomor, nama pelanggan, alamat),
     tombol aksi opsional. Dipakai untuk status terminal (SELESAI/DIBATALKAN),
     "sudah diambil orang lain", "belum diambil", dan "belum diaktivasi".
     Variabel: $icon, $tone (green|amber|red|gray), $heading, $detail (opsional),
     $workOrder, $actionUrl (opsional), $actionLabel (opsional). --}}
@php
    $toneClasses = [
        'green' => 'border-green-200 bg-green-50 text-green-800',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-800',
        'red' => 'border-red-200 bg-red-50 text-red-800',
        'gray' => 'border-gray-200 bg-gray-50 text-gray-700',
    ][$tone ?? 'gray'] ?? 'border-gray-200 bg-gray-50 text-gray-700';
@endphp
<div class="border {{ $toneClasses }} rounded-md p-4 text-sm mb-4">
    <div class="flex items-start gap-3">
        <span class="text-xl leading-none">{{ $icon ?? 'ℹ️' }}</span>
        <div>
            <p class="font-semibold text-base">{{ $heading }}</p>
            @isset($detail)
                @if ($detail)
                    <p class="mt-1">{{ $detail }}</p>
                @endif
            @endisset
        </div>
    </div>
</div>

<div class="border border-gray-200 rounded-md p-4 text-sm">
    <h2 class="font-semibold text-gray-700 mb-2">Ringkasan Work Order</h2>
    <dl class="space-y-1 text-gray-700">
        <div class="flex justify-between">
            <dt class="text-gray-500">Nomor WO</dt>
            <dd class="font-medium">#{{ $workOrder->id }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-gray-500">Pelanggan</dt>
            <dd class="font-medium text-right">{{ $workOrder->customer?->name ?? '-' }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-gray-500">Alamat</dt>
            <dd class="font-medium text-right">{{ $workOrder->customer?->address ?? '-' }}</dd>
        </div>
    </dl>
</div>

@isset($actionUrl)
    @if ($actionUrl)
        <a href="{{ $actionUrl }}"
            class="mt-4 block w-full text-center bg-primary text-white rounded-md py-2 text-sm font-semibold hover:opacity-90">
            {{ $actionLabel ?? 'Lanjut' }}
        </a>
    @endif
@endisset
