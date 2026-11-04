{{-- v0.13.4.5 — Header identitas WO untuk state "tersedia" (claim.show).
     Nomor WO + jadwal (format Indonesia), nama pelanggan BESAR, alamat, nama
     paket. SENGAJA tanpa nomor telepon pelanggan & tanpa username PPPoE —
     tidak ada data sensitif baru di halaman publik (keputusan Agung).
     Variabel: $workOrder, $claimingTechnician (opsional). --}}
<div class="border border-gray-200 rounded-md p-4 mb-6 bg-gray-50">
    <div class="flex items-center justify-between mb-2">
        <span class="text-xs font-semibold text-gray-500">WO #{{ $workOrder->id }}</span>
        <span class="text-xs text-gray-500">
            @if ($workOrder->scheduled_at)
                {{-- Locale 'id' dipaksa agar nama hari/bulan selalu Bahasa
                     Indonesia; zona waktu mengikuti app (Asia/Jakarta). --}}
                {{ $workOrder->scheduled_at->locale('id')->translatedFormat('l, d F Y · H:i') }}
            @else
                Jadwal belum ditentukan
            @endif
        </span>
    </div>
    <p class="text-2xl font-bold text-gray-900 leading-tight">{{ $workOrder->customer?->name ?? '-' }}</p>
    <p class="text-sm text-gray-600 mt-1">{{ $workOrder->customer?->address ?? '-' }}</p>
    <dl class="mt-3 text-sm text-gray-700 space-y-1">
        <div class="flex justify-between">
            <dt class="text-gray-500">Paket</dt>
            <dd class="font-medium">{{ $workOrder->customer?->pppPackage?->name ?? '-' }}</dd>
        </div>
        @isset($claimingTechnician)
            <div class="flex justify-between">
                <dt class="text-gray-500">Teknisi Utama</dt>
                <dd class="font-medium">{{ $claimingTechnician->name }}</dd>
            </div>
        @endisset
    </dl>
</div>
