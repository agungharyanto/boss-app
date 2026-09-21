<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Klaim Work Order - {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-gray-100 min-h-screen py-6 px-4">
        {{-- v0.13.4.1 — Halaman klaim WO via signed-link. Public-facing,
             TANPA login sama sekali (lihat WorkOrderClaimController's own
             docblock). Sengaja BUKAN komponen Livewire — form HTML biasa
             (method POST ke url()->full()) supaya submit tetap membawa
             signature URL yang sama persis yang sudah tervalidasi
             middleware 'signed' saat GET; AJAX internal Livewire sendiri
             (endpoint /livewire-*/update) TIDAK dilindungi signature sama
             sekali, jadi tidak dipakai di sini. @livewireScripts di bawah
             HANYA dipakai untuk runtime Alpine.js yang ikut ter-bundle
             dengannya — tidak ada satu pun wire:model/komponen Livewire
             di halaman ini. --}}
        <div class="max-w-2xl mx-auto bg-white rounded-md shadow p-6">
            <h1 class="text-xl font-semibold text-gray-800 mb-1">Klaim Work Order</h1>
            <p class="text-sm text-gray-500 mb-4">{{ config('app.name') }}</p>

            <div class="border border-gray-200 rounded-md p-4 mb-6 bg-gray-50">
                <h2 class="text-sm font-semibold text-gray-700 mb-2">Ringkasan Work Order</h2>
                <dl class="text-sm text-gray-700 space-y-1">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Pelanggan</dt>
                        <dd class="font-medium">{{ $workOrder->customer?->name ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Alamat</dt>
                        <dd class="font-medium text-right">{{ $workOrder->customer?->address ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Paket</dt>
                        <dd class="font-medium">{{ $workOrder->customer?->pppPackage?->name ?? '-' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Teknisi Utama</dt>
                        <dd class="font-medium">{{ $claimingTechnician->name }}</dd>
                    </div>
                </dl>
            </div>

            @if ($alreadyClaimed)
                {{-- v0.13.4.1 amendment — link ini masih valid secara signature
                     (lolos middleware 'signed'), tapi WO-nya sudah diklaim
                     lebih dulu (claimed_at terisi) — lewat link lain yang
                     masih berlaku, atau link baru dari reminder harian.
                     Tampilkan ringkasan read-only, BUKAN form kosong lagi
                     dan BUKAN pesan error generik. --}}
                <div class="border border-amber-200 bg-amber-50 text-amber-800 rounded-md p-4 text-sm mb-4">
                    Work Order ini <strong>sudah diklaim</strong> oleh
                    <strong>{{ $workOrder->claimedByTechnician?->name ?? 'teknisi lain' }}</strong>
                    pada {{ $workOrder->claimed_at->translatedFormat('d M Y, H:i') }}. Link ini tidak bisa dipakai untuk klaim ulang.
                </div>

                <div class="border border-gray-200 rounded-md p-4 text-sm space-y-3">
                    <div>
                        <h3 class="font-semibold text-gray-700 mb-1">Partner Kerja</h3>
                        @if ($workOrder->claimPartners->isEmpty())
                            <p class="text-gray-500">Tidak ada partner tercatat.</p>
                        @else
                            <ul class="list-disc list-inside text-gray-700">
                                @foreach ($workOrder->claimPartners as $partner)
                                    <li>{{ $partner->name }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div>
                        <h3 class="font-semibold text-gray-700 mb-1">Alat Non-Modem</h3>
                        @if ($workOrder->toolUsages->isEmpty())
                            <p class="text-gray-500">Tidak ada alat tercatat.</p>
                        @else
                            <ul class="list-disc list-inside text-gray-700">
                                @foreach ($workOrder->toolUsages as $usage)
                                    <li>{{ $usage->toolType?->name ?? '-' }} × {{ $usage->quantity }} (dibawa {{ $usage->technician?->name ?? '-' }})</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div>
                        <h3 class="font-semibold text-gray-700 mb-1">Modem</h3>
                        @if ($workOrder->modemUnits->isEmpty())
                            <p class="text-gray-500">Tidak ada modem tercatat.</p>
                        @else
                            <ul class="list-disc list-inside text-gray-700">
                                @foreach ($workOrder->modemUnits as $unit)
                                    <li>SN {{ $unit->serial_number }} / MAC {{ $unit->mac_address }} (dibawa {{ $unit->technician?->name ?? '-' }})</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @elseif ($submitted)
                <div class="border border-green-200 bg-green-50 text-green-800 rounded-md p-4 text-sm">
                    Klaim berhasil dicatat. Terima kasih — informasi partner kerja dan alat yang dibawa
                    sudah tersimpan untuk Work Order ini.
                </div>
            @else
                @isset($submitError)
                    <div class="border border-red-200 bg-red-50 text-red-800 rounded-md p-3 mb-4 text-sm">
                        {{ $submitError }}
                    </div>
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

                <form method="POST" action="{{ url()->full() }}"
                    x-data="claimForm({
                        technicianId: {{ $claimingTechnician->id }},
                        technicianName: @js($claimingTechnician->name),
                        partners: @js($partnerOptions->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()),
                    })">
                    @csrf

                    <div class="mb-6">
                        <h2 class="text-sm font-semibold text-gray-700 mb-2">Partner Kerja (opsional)</h2>
                        <div class="space-y-1">
                            <template x-if="partners.length === 0">
                                <p class="text-sm text-gray-500">Tidak ada teknisi aktif lain di tenant ini.</p>
                            </template>
                            <template x-for="partner in partners" :key="partner.id">
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" :value="partner.id" x-model.number="selectedPartnerIds"
                                        class="rounded border-gray-300">
                                    <span x-text="partner.name"></span>
                                </label>
                            </template>
                        </div>
                        <template x-for="pid in selectedPartnerIds" :key="'partner-input-' + pid">
                            <input type="hidden" name="partner_ids[]" :value="pid">
                        </template>
                    </div>

                    <div class="mb-6">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="text-sm font-semibold text-gray-700">Alat Non-Modem</h2>
                            <button type="button" @click="addToolRow()"
                                class="text-xs text-primary hover:underline">+ Tambah Baris</button>
                        </div>
                        <template x-if="tools.length === 0">
                            <p class="text-sm text-gray-500">Belum ada baris alat.</p>
                        </template>
                        <div class="space-y-2">
                            <template x-for="(row, index) in tools" :key="row.key">
                                <div class="grid grid-cols-12 gap-2 items-center">
                                    <select :name="'tools[' + index + '][tool_type_id]'" x-model.number="row.tool_type_id"
                                        class="col-span-5 border-gray-300 rounded-md text-sm">
                                        <option value="">-- Pilih Alat --</option>
                                        @foreach ($toolTypes as $toolType)
                                            <option value="{{ $toolType->id }}">{{ $toolType->name }}</option>
                                        @endforeach
                                    </select>
                                    <input type="number" min="1" :name="'tools[' + index + '][quantity]'"
                                        x-model.number="row.quantity" placeholder="Qty"
                                        class="col-span-2 border-gray-300 rounded-md text-sm">
                                    <select :name="'tools[' + index + '][technician_id]'" x-model.number="row.technician_id"
                                        class="col-span-4 border-gray-300 rounded-md text-sm">
                                        <option :value="technicianId" x-text="technicianName + ' (saya)'"></option>
                                        <template x-for="bearer in bearerOptions()" :key="'tool-bearer-' + row.key + '-' + bearer.id">
                                            <option :value="bearer.id" x-text="bearer.name"></option>
                                        </template>
                                    </select>
                                    <button type="button" @click="removeToolRow(index)"
                                        class="col-span-1 text-red-600 hover:underline text-xs">Hapus</button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="mb-6">
                        <div class="flex items-center justify-between mb-2">
                            <h2 class="text-sm font-semibold text-gray-700">Modem</h2>
                            <button type="button" @click="addModemRow()"
                                class="text-xs text-primary hover:underline">+ Tambah Modem</button>
                        </div>
                        <template x-if="modems.length === 0">
                            <p class="text-sm text-gray-500">Belum ada baris modem.</p>
                        </template>
                        <div class="space-y-2">
                            <template x-for="(row, index) in modems" :key="row.key">
                                <div class="grid grid-cols-12 gap-2 items-center">
                                    <input type="text" :name="'modems[' + index + '][serial_number]'"
                                        x-model="row.serial_number" placeholder="Serial Number"
                                        class="col-span-4 border-gray-300 rounded-md text-sm">
                                    <input type="text" :name="'modems[' + index + '][mac_address]'"
                                        x-model="row.mac_address" placeholder="MAC Address"
                                        class="col-span-4 border-gray-300 rounded-md text-sm">
                                    <select :name="'modems[' + index + '][technician_id]'" x-model.number="row.technician_id"
                                        class="col-span-3 border-gray-300 rounded-md text-sm">
                                        <option :value="technicianId" x-text="technicianName + ' (saya)'"></option>
                                        <template x-for="bearer in bearerOptions()" :key="'modem-bearer-' + row.key + '-' + bearer.id">
                                            <option :value="bearer.id" x-text="bearer.name"></option>
                                        </template>
                                    </select>
                                    <button type="button" @click="removeModemRow(index)"
                                        class="col-span-1 text-red-600 hover:underline text-xs">Hapus</button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <button type="submit"
                        class="w-full bg-primary text-white rounded-md py-2 text-sm font-semibold hover:opacity-90">
                        Kirim Klaim
                    </button>
                </form>
            @endif
        </div>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('claimForm', (config) => ({
                    technicianId: config.technicianId,
                    technicianName: config.technicianName,
                    partners: config.partners,
                    selectedPartnerIds: [],
                    tools: [],
                    modems: [],
                    _nextKey: 1,

                    bearerOptions() {
                        return this.partners.filter((p) => this.selectedPartnerIds.includes(p.id));
                    },
                    addToolRow() {
                        this.tools.push({ key: this._nextKey++, tool_type_id: '', quantity: 1, technician_id: this.technicianId });
                    },
                    removeToolRow(index) {
                        this.tools.splice(index, 1);
                    },
                    addModemRow() {
                        this.modems.push({ key: this._nextKey++, serial_number: '', mac_address: '', technician_id: this.technicianId });
                    },
                    removeModemRow(index) {
                        this.modems.splice(index, 1);
                    },
                }));
            });
        </script>

        @livewireScripts
    </body>
</html>
