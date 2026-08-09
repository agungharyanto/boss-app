<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-2">
        <h1 class="text-2xl font-semibold" style="color: var(--color-text)">Mapping Parameter CPE</h1>
        @if (! $showForm)
            <button wire:click="create" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                Tambah Mapping
            </button>
        @endif
    </div>
    <p class="text-sm text-gray-500 mb-6">
        Mapping path parameter TR-069 per vendor/model (OUI + Product Class) ke nilai dunia-nyata
        (mis. RX/TX power dBm). Baris hanya "Terverifikasi" kalau sudah benar-benar dicek terhadap
        device nyata lewat panel "Tes Resolve" di bawah.
    </p>

    {{-- FORM CREATE/EDIT --}}
    @if ($showForm)
        <div class="p-4 rounded-md border border-gray-200 space-y-4 mb-6">
            <h2 class="font-medium">{{ $editingId ? 'Edit Mapping' : 'Tambah Mapping Baru' }}</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">OUI</label>
                    <input type="text" wire:model="oui" placeholder="F86CE1" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono">
                    @error('oui') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Product Class</label>
                    <input type="text" wire:model="productClass" placeholder="F663NV3a" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono">
                    @error('productClass') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 mt-1">Persis nilai <code>_deviceId._ProductClass</code> dari GenieACS, bukan nama marketing.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Parameter Key</label>
                    <input type="text" wire:model="parameterKey" placeholder="rx_power_dbm" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono">
                    @error('parameterKey') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Value Type (opsional)</label>
                    <input type="text" wire:model="valueType" placeholder="xsd:int" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono">
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-1">Parameter Path (TR-069)</label>
                    <input type="text" wire:model="parameterPath" placeholder="InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono">
                    @error('parameterPath') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Formula Konversi</label>
                    <select wire:model="conversionFormula" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                        @foreach ($this->conversionFormulaOptions() as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    @error('conversionFormula') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1">Conversion Params (JSON)</label>
                    <input type="text" wire:model="conversionParamsJson" placeholder='{"scale": 0.0001}' class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono">
                    @error('conversionParamsJson') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2">
                    <label class="block text-sm font-medium mb-1">Catatan</label>
                    <textarea wire:model="notes" rows="2" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></textarea>
                </div>
            </div>

            @if ($editingId)
                <p class="text-xs text-amber-600">Mengubah definisi ini akan menurunkan status baris kembali ke "belum terverifikasi".</p>
            @endif

            <div class="flex gap-2">
                <button wire:click="save" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">Simpan</button>
                <button wire:click="cancel" class="px-4 py-2 border border-gray-300 rounded-md text-sm">Batal</button>
            </div>
        </div>
    @endif

    {{-- TABEL MAPPING --}}
    <div class="overflow-x-auto border border-gray-200 rounded-md mb-8">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left">
                <tr>
                    <th class="px-3 py-2">OUI</th>
                    <th class="px-3 py-2">Product Class</th>
                    <th class="px-3 py-2">Key</th>
                    <th class="px-3 py-2">Path</th>
                    <th class="px-3 py-2">Formula</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($maps as $map)
                    <tr class="border-t border-gray-100">
                        <td class="px-3 py-2 font-mono">{{ $map->oui }}</td>
                        <td class="px-3 py-2 font-mono">{{ $map->product_class }}</td>
                        <td class="px-3 py-2 font-mono">{{ $map->parameter_key }}</td>
                        <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $map->parameter_path }}</td>
                        <td class="px-3 py-2">{{ $map->conversion_formula->value }}</td>
                        <td class="px-3 py-2">
                            @if ($map->isVerified())
                                <span class="text-green-600 text-xs">Terverifikasi · {{ $map->verified_at->diffForHumans() }}</span>
                            @else
                                <span class="text-gray-400 text-xs">Belum terverifikasi</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <button wire:click="edit({{ $map->id }})" class="text-primary hover:underline text-xs mr-3">Edit</button>
                            <button wire:click="delete({{ $map->id }})" wire:confirm="Hapus mapping ini?" class="text-red-600 hover:underline text-xs">Hapus</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada mapping.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div>{{ $maps->links() }}</div>

    {{-- TES RESOLVE --}}
    <div class="p-4 rounded-md border border-gray-200 space-y-4">
        <h2 class="font-medium">Tes Resolve</h2>
        <p class="text-sm text-gray-500">
            Ambil device nyata dari GenieACS, cocokkan ke mapping di atas, tarik raw value, konversi.
        </p>
        <div class="flex gap-2">
            <input type="text" wire:model="resolveDeviceId" placeholder="F86CE1-F663NV3a-ZICG296C2E7B" class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm font-mono">
            <button wire:click="resolve" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm whitespace-nowrap">Resolve</button>
        </div>
        @error('resolveDeviceId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

        @if ($resolveResult !== null)
            @if (empty($resolveResult))
                <p class="text-sm text-gray-400">Tidak ada mapping yang cocok untuk device ini (OUI/Product Class-nya belum ada di katalog di atas), atau device tidak ditemukan di GenieACS.</p>
            @else
                <table class="w-full text-sm border border-gray-200 rounded-md overflow-hidden">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th class="px-3 py-2">Key</th>
                            <th class="px-3 py-2">Raw</th>
                            <th class="px-3 py-2">Nilai</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($resolveResult as $key => $result)
                            <tr class="border-t border-gray-100">
                                <td class="px-3 py-2 font-mono">{{ $key }}</td>
                                <td class="px-3 py-2 font-mono">{{ $result['raw_value'] ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    @if ($result['error'])
                                        <span class="text-red-600 text-xs">{{ $result['error'] }}</span>
                                    @else
                                        {{ number_format($result['value'], 2) }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    @if (! $result['error'] && ! $result['verified'])
                                        <button wire:click="markVerified('{{ $key }}')" class="text-primary hover:underline text-xs">Tandai Terverifikasi</button>
                                    @elseif ($result['verified'])
                                        <span class="text-green-600 text-xs">✓ Terverifikasi</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </div>
</div>
