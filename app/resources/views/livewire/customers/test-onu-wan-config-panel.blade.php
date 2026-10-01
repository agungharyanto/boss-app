<div class="bg-white border border-amber-200 rounded-lg p-4 space-y-6">
    <div class="flex items-center gap-2">
        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">TEST FIXTURE</span>
        <h2 class="text-sm font-semibold text-gray-700">Konfigurasi WAN ONU (Test)</h2>
    </div>

    @if ($statusMessage)
        <div class="text-sm text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2">{{ $statusMessage }}</div>
    @endif
    @if ($errorMessage)
        <div class="text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2">{{ $errorMessage }}</div>
    @endif

    {{-- Section A: Update ONU Mode --}}
    <div class="space-y-3">
        <h3 class="text-xs font-medium text-gray-500 uppercase">Update ONU Mode</h3>

        <div>
            <label class="block text-xs text-gray-600 mb-1">Paket / VLAN</label>
            <select wire:model="packageId" class="border-gray-300 rounded-md px-3 py-2 text-sm w-full">
                <option value="">-- Pilih Paket --</option>
                @foreach ($this->packageOptions as $opt)
                    <option value="{{ $opt['id'] }}">{{ $opt['label'] }}</option>
                @endforeach
            </select>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs text-gray-600 mb-1">ONU Mode</label>
                <select wire:model="onuMode" class="border-gray-300 rounded-md px-3 py-2 text-sm w-full">
                    <option value="routing">Routing</option>
                    <option value="bridging" disabled>Bridging (belum didukung)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Config Method</label>
                <select wire:model="configMethod" class="border-gray-300 rounded-md px-3 py-2 text-sm w-full">
                    <option value="omci">OMCI</option>
                    <option value="tr069">TR-069</option>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-xs text-gray-600 mb-1">WAN Mode</label>
            <select wire:model.live="wanMode" class="border-gray-300 rounded-md px-3 py-2 text-sm w-full">
                <option value="pppoe">PPPoE</option>
                <option value="dhcp" disabled>DHCP (Segera hadir)</option>
                <option value="static" disabled>Static IP (Segera hadir)</option>
                <option value="webpage" disabled>Setup via ONU webpage (Segera hadir)</option>
            </select>
        </div>

        @if ($wanMode === 'pppoe')
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-gray-600 mb-1">PPPoE Username</label>
                    <input type="text" wire:model="pppoeUsername" class="border-gray-300 rounded-md px-3 py-2 text-sm w-full" />
                </div>
                <div>
                    <label class="block text-xs text-gray-600 mb-1">PPPoE Password <span class="text-gray-400">(kosong = tidak diubah)</span></label>
                    <input type="password" wire:model="pppoePassword" class="border-gray-300 rounded-md px-3 py-2 text-sm w-full" />
                </div>
            </div>
        @endif
    </div>

    {{-- Section B: Attached VLANs --}}
    <div class="space-y-2">
        <h3 class="text-xs font-medium text-gray-500 uppercase">Attached VLANs</h3>
        <p class="text-xs text-gray-500">VLAN yang diizinkan lewat ke ONU. VLAN 9 (Remote Management) wajib &amp; terkunci.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 border border-gray-200 rounded-md p-3">
            @foreach ($this->vlanOptions as $opt)
                <label class="flex items-center gap-2 text-sm {{ $opt['locked'] ? 'text-gray-500' : 'text-gray-700' }}">
                    <input type="checkbox" wire:model="selectedVlans" value="{{ $opt['vlan'] }}"
                        @if ($opt['locked']) checked disabled @endif
                        class="rounded border-gray-300" />
                    <span>{{ $opt['label'] }}</span>
                </label>
            @endforeach
        </div>
    </div>

    <div class="flex items-center gap-3 pt-2 border-t border-gray-100">
        <button type="button" wire:click="save"
            class="px-4 py-2 text-sm bg-gray-800 text-white rounded-md hover:bg-gray-700">
            Simpan Konfigurasi
        </button>
        <button type="button" wire:click="apply" wire:confirm="Terapkan ke ONU sekarang? Ini akan menulis ke OLT (delete + registrasi ulang ONU)."
            class="px-4 py-2 text-sm bg-amber-600 text-white rounded-md hover:bg-amber-500">
            Terapkan ke ONU
        </button>
    </div>
</div>
