<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-800">{{ __('Template Konfig CPE') }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ __('Dikelompokkan per Tipe Modem — pengganti Konfig Remote (fleet-wide). Assignment ke device di Detail Perangkat CPE. Belum sync ke GenieACS.') }}</p>
        </div>
        @if ($canManage)
            <div class="flex gap-2">
                <button type="button" wire:click="openModemTypeModal" class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 text-sm">
                    {{ __('Kelola Tipe Modem') }}
                </button>
                <button type="button" wire:click="openCreateForModemType" class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm">
                    {{ __('+ Template Baru') }}
                </button>
            </div>
        @endif
    </div>

    <div class="space-y-6">
        {{-- Grup "Generic/Tanpa Tipe Modem" --}}
        <div class="border border-gray-200 rounded-md overflow-hidden">
            <div class="bg-gray-50 px-4 py-2 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-800">{{ __('Generic (Tanpa Tipe Modem)') }}</h2>
                @if ($canManage)
                    <button type="button" wire:click="openCreateForModemType" class="text-xs text-primary hover:underline">
                        {{ __('+ Tambah Template') }}
                    </button>
                @endif
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-white">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nama Template') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('WAN1') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('WAN2') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Sync GenieACS') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse ($genericTemplates as $template)
                        <tr wire:key="generic-template-{{ $template->id }}">
                            <td class="px-4 py-2 text-sm text-gray-800 font-medium">{{ $template->name }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $template->enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                    {{ $template->enabled ? __('Aktif') : __('Nonaktif') }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $template->wan1_enabled ? "VLAN {$template->wan1_vlan}" : __('Off') }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $template->wan2_enabled ? "VLAN {$template->wan2_vlan}" : __('Off') }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $template->genieacs_sync_status->badgeClasses() }}">
                                    {{ $template->genieacs_sync_status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm text-right space-x-2 whitespace-nowrap">
                                @if ($canManage)
                                    <button wire:click="editTemplate({{ $template->id }})" class="text-primary hover:underline">{{ __('Edit') }}</button>
                                    <button wire:click="deleteTemplate({{ $template->id }})" wire:confirm="{{ __('Hapus template ini?') }}" class="text-red-600 hover:underline">{{ __('Hapus') }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-4 text-sm text-gray-400 italic text-center">{{ __('Belum ada template generic.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Satu grup per Tipe Modem --}}
        @forelse ($modemTypes as $modemType)
            @php $templatesForType = $templatesByModemType->get($modemType->id, collect()); @endphp
            <div class="border border-gray-200 rounded-md overflow-hidden">
                <div class="bg-gray-50 px-4 py-2 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-800">
                        {{ $modemType->name }}
                        @unless ($modemType->is_active)
                            <span class="text-xs text-gray-400">({{ __('Nonaktif') }})</span>
                        @endunless
                    </h2>
                    @if ($canManage)
                        <button type="button" wire:click="openCreateForModemType({{ $modemType->id }})" class="text-xs text-primary hover:underline">
                            {{ __('+ Tambah Template') }}
                        </button>
                    @endif
                </div>
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-white">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nama Template') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('WAN1') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('WAN2') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Sync GenieACS') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($templatesForType as $template)
                            <tr wire:key="modem-{{ $modemType->id }}-template-{{ $template->id }}">
                                <td class="px-4 py-2 text-sm text-gray-800 font-medium">{{ $template->name }}</td>
                                <td class="px-4 py-2 text-sm">
                                    <span class="px-2 py-0.5 rounded-full text-xs {{ $template->enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                        {{ $template->enabled ? __('Aktif') : __('Nonaktif') }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-600">{{ $template->wan1_enabled ? "VLAN {$template->wan1_vlan}" : __('Off') }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600">{{ $template->wan2_enabled ? "VLAN {$template->wan2_vlan}" : __('Off') }}</td>
                                <td class="px-4 py-2 text-sm">
                                    <span class="px-2 py-0.5 rounded-full text-xs {{ $template->genieacs_sync_status->badgeClasses() }}">
                                        {{ $template->genieacs_sync_status->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-sm text-right space-x-2 whitespace-nowrap">
                                    @if ($canManage)
                                        <button wire:click="editTemplate({{ $template->id }})" class="text-primary hover:underline">{{ __('Edit') }}</button>
                                        <button wire:click="deleteTemplate({{ $template->id }})" wire:confirm="{{ __('Hapus template ini?') }}" class="text-red-600 hover:underline">{{ __('Hapus') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-4 text-sm text-gray-400 italic text-center">{{ __('Belum ada template untuk Tipe Modem ini.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @empty
            <div class="border border-gray-200 rounded-md p-6 text-center text-sm text-gray-500">
                {{ __('Belum ada Tipe Modem — kelola dulu lewat tombol "Kelola Tipe Modem" di atas.') }}
            </div>
        @endforelse
    </div>

    {{-- Modal form Template (create/edit) --}}
    @if ($showTemplateForm)
        <div class="fixed inset-0 bg-black/40 z-[1100] flex items-center justify-center p-4" wire:click.self="closeTemplateForm">
            <div class="bg-white rounded-md shadow-lg max-w-lg w-full p-6 space-y-4">
                <h2 class="text-lg font-semibold text-gray-800">
                    {{ $editingTemplateId ? __('Edit Template') : __('Template Baru') }}
                </h2>

                <form wire:submit="saveTemplate" class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Nama Template') }}</label>
                        <input type="text" wire:model="name" placeholder="{{ __('Nama bebas, mis. ZTE F609 - Standar') }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Tipe Modem') }}</label>
                        <select wire:model="modemTypeSelection" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">{{ __('-- Pilih Tipe Modem --') }}</option>
                            <option value="generic">{{ __('Generic (Tanpa Tipe Modem)') }}</option>
                            @foreach ($modemTypes as $modemType)
                                <option value="{{ $modemType->id }}">{{ $modemType->name }}{{ $modemType->is_active ? '' : ' ('.__('Nonaktif').')' }}</option>
                            @endforeach
                        </select>
                        @error('modemTypeSelection') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="enabled"> {{ __('Aktifkan template ini (push preset GenieACS)') }}
                    </label>

                    <div class="border-t border-gray-200 pt-3 space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="wan1Enabled"> {{ __('WAN1 (PPPoE internet) aktif') }}
                        </label>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs text-gray-500">{{ __('VLAN WAN1') }}</label>
                                <input type="number" wire:model="wan1Vlan" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                @error('wan1Vlan') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500">{{ __('Username PPPoE') }}</label>
                                <input type="text" wire:model="wan1PppoeUsername" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                @error('wan1PppoeUsername') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500">{{ __('Password PPPoE') }}</label>
                                <input type="text" wire:model="wan1PppoePassword" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                @error('wan1PppoePassword') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 pt-3 space-y-2">
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" wire:model="wan2Enabled"> {{ __('WAN2 (bridge kedua) aktif') }}
                        </label>
                        <div>
                            <label class="block text-xs text-gray-500">{{ __('VLAN WAN2') }}</label>
                            <input type="number" wire:model="wan2Vlan" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm text-sm">
                            @error('wan2Vlan') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="flex gap-2 pt-2">
                        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 text-sm">{{ __('Simpan') }}</button>
                        <button type="button" wire:click="closeTemplateForm" class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 text-sm">{{ __('Batal') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal CRUD Tipe Modem --}}
    @if ($showModemTypeModal)
        <div class="fixed inset-0 bg-black/40 z-[1100] flex items-center justify-center p-4" wire:click.self="closeModemTypeModal">
            <div class="bg-white rounded-md shadow-lg max-w-lg w-full p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-800">{{ __('Kelola Tipe Modem') }}</h2>
                    <button type="button" wire:click="closeModemTypeModal" class="text-gray-400 hover:text-gray-600">&times;</button>
                </div>

                <form wire:submit="saveModemType" class="space-y-2">
                    <div>
                        <input type="text" wire:model="modemTypeName" placeholder="{{ __('Nama Tipe Modem, mis. ZTE F609 Dual-Band') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                        @error('modemTypeName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <input type="text" wire:model="manufacturerMatchPatterns" placeholder="{{ __('Kode OUI (pisah koma), mis. ZICG,CIOT — dasar auto-suggest') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                        <p class="text-xs text-gray-400 mt-0.5">{{ __('Dicocokkan ke cpe_devices.manufacturer. Kosong = tidak pernah auto-suggest untuk Tipe Modem ini.') }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="flex items-center gap-1 text-xs text-gray-600 whitespace-nowrap">
                            <input type="checkbox" wire:model="modemTypeIsActive"> {{ __('Aktif') }}
                        </label>
                        <button type="submit" class="px-3 py-2 bg-primary text-white rounded-md hover:opacity-90 text-sm whitespace-nowrap ml-auto">
                            {{ $editingModemTypeId ? __('Update') : __('Tambah') }}
                        </button>
                        @if ($editingModemTypeId)
                            <button type="button" wire:click="cancelEditModemType" class="px-3 py-2 border border-gray-300 rounded-md hover:bg-gray-50 text-sm">{{ __('Batal') }}</button>
                        @endif
                    </div>
                </form>

                @error('modemTypeDelete') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                <div class="border border-gray-200 rounded-md divide-y divide-gray-200 max-h-72 overflow-y-auto">
                    @forelse ($modemTypes as $modemType)
                        <div wire:key="modem-type-{{ $modemType->id }}" class="px-3 py-2 flex items-center justify-between text-sm">
                            <span class="text-gray-800">
                                {{ $modemType->name }}
                                @if ($modemType->manufacturer_match_patterns)
                                    <span class="text-xs text-gray-400 font-mono">({{ $modemType->manufacturer_match_patterns }})</span>
                                @endif
                                @unless ($modemType->is_active)
                                    <span class="text-xs text-gray-400">({{ __('Nonaktif') }})</span>
                                @endunless
                            </span>
                            <span class="space-x-2 whitespace-nowrap">
                                <button wire:click="editModemType({{ $modemType->id }})" class="text-primary hover:underline">{{ __('Edit') }}</button>
                                <button wire:click="deleteModemType({{ $modemType->id }})" wire:confirm="{{ __('Hapus Tipe Modem ini?') }}" class="text-red-600 hover:underline">{{ __('Hapus') }}</button>
                            </span>
                        </div>
                    @empty
                        <p class="px-3 py-4 text-sm text-gray-400 text-center">{{ __('Belum ada Tipe Modem.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
