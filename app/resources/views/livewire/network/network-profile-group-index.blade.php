{{--
    v0.14.3 — same conditional wire:poll pattern as customer-ip-pool-index
    (v0.14.2.2), reused verbatim. No wire:loading exclusion needed — this
    component has no loading indicator anywhere (confirmed by grep).
--}}
<div class="p-6 max-w-6xl mx-auto" @if ($hasPendingSync) wire:poll.5s="$refresh" @endif>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Grup Profil') }}</h1>

        @if ($canManage)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Grup Profil Baru') }}
            </button>
        @endif
    </div>

    @if ($showCreateForm)
        <form wire:submit="createGroup" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('NAS') }}</label>
                    <select wire:model.live="nasId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">{{ __('-- Pilih NAS --') }}</option>
                        @foreach ($nasOptions as $nasOption)
                            <option value="{{ $nasOption->id }}">{{ $nasOption->name }}</option>
                        @endforeach
                    </select>
                    @error('nasId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Nama Grup Profil') }}</label>
                    <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Tipe') }}</label>
                    <select wire:model.live="type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="ppp">{{ __('PPP') }}</option>
                        <option value="hotspot">{{ __('Hotspot') }}</option>
                    </select>
                    @error('type') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('IP Pool') }}</label>
                    <select wire:model="customerIpPoolId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" @if (! $nasId) disabled @endif>
                        <option value="">{{ $nasId ? __('-- Pilih IP Pool --') : __('-- Pilih NAS dulu --') }}</option>
                        @foreach ($poolOptionsForNas as $poolOption)
                            <option value="{{ $poolOption->id }}">{{ $poolOption->name }}</option>
                        @endforeach
                    </select>
                    @error('customerIpPoolId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('DNS Primer') }}</label>
                    <input type="text" wire:model="dnsPrimary" placeholder="8.8.8.8" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('dnsPrimary') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('DNS Sekunder') }}</label>
                    <input type="text" wire:model="dnsSecondary" placeholder="8.8.4.4" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('dnsSecondary') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Parent Queue') }}</label>
                    <input type="text" wire:model="parentQueue" placeholder="{{ __('(opsional)') }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('parentQueue') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="isActive"> {{ __('Aktif') }}
            </label>

            {{-- v0.14.4 amendment — NAS wajib dipilih dulu sebelum Simpan
                 bisa diklik, defense-in-depth di atas validasi backend
                 'required' yang sudah ada (baseRules()). --}}
            <button type="submit" @if (! $nasId) disabled @endif class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-green-600">
                {{ __('Simpan') }}
            </button>
            @if (! $nasId)
                <p class="text-xs text-amber-600 mt-1">{{ __('Pilih NAS terlebih dahulu sebelum menyimpan.') }}</p>
            @endif
        </form>
    @endif

    <div class="mb-4 flex flex-col md:flex-row gap-3">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama grup profil...') }}"
            class="w-full md:flex-1 rounded-md border-gray-300 shadow-sm"
        >
        <select wire:model.live="filterNasId" class="w-full md:flex-1 rounded-md border-gray-300 shadow-sm">
            <option value="">{{ __('Semua NAS') }}</option>
            @foreach ($nasOptions as $nasOption)
                <option value="{{ $nasOption->id }}">{{ $nasOption->name }}</option>
            @endforeach
        </select>
        <button
            type="button" wire:click="$refresh"
            class="px-4 py-2 border border-gray-300 rounded-md hover:bg-gray-50 text-sm whitespace-nowrap"
        >
            {{ __('Muat Ulang') }}
        </button>
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer" wire:click="sortByColumn('name')">
                        {{ __('Nama') }} @if ($sortBy === 'name') {{ $sortDir === 'asc' ? '▲' : '▼' }} @endif
                    </th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('NAS') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Tipe') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('IP Pool') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Sync Router') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($groups as $group)
                    <tr wire:key="group-{{ $group->id }}">
                        @if ($editingGroupId === $group->id)
                            <td colspan="7" class="px-4 py-3">
                                <form wire:submit="updateGroup" class="space-y-3">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <select wire:model.live="editNasId" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @foreach ($nasOptions as $nasOption)
                                                <option value="{{ $nasOption->id }}">{{ $nasOption->name }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" wire:model="editName" placeholder="{{ __('Nama Grup Profil') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                    </div>
                                    @error('editNasId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @error('editName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <select wire:model.live="editType" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            <option value="ppp">{{ __('PPP') }}</option>
                                            <option value="hotspot">{{ __('Hotspot') }}</option>
                                        </select>
                                        <select wire:model="editCustomerIpPoolId" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            <option value="">{{ __('-- Pilih IP Pool --') }}</option>
                                            @foreach ($editPoolOptionsForNas as $poolOption)
                                                <option value="{{ $poolOption->id }}">{{ $poolOption->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @error('editType') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @error('editCustomerIpPoolId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror

                                    <div class="grid grid-cols-3 gap-3">
                                        <input type="text" wire:model="editDnsPrimary" placeholder="{{ __('DNS Primer') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        <input type="text" wire:model="editDnsSecondary" placeholder="{{ __('DNS Sekunder') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        <input type="text" wire:model="editParentQueue" placeholder="{{ __('Parent Queue') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                    </div>

                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" wire:model="editIsActive"> {{ __('Aktif') }}
                                    </label>

                                    <div class="flex gap-2">
                                        <button type="submit" class="text-sm px-3 py-1.5 bg-green-600 text-white rounded-md hover:bg-green-700">{{ __('Simpan') }}</button>
                                        <button type="button" wire:click="cancelEdit" class="text-sm px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Batal') }}</button>
                                    </div>
                                </form>
                            </td>
                        @else
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $group->name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $group->nas->name ?? '-' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $group->type->label() }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $group->customerIpPool->name ?? '-' }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $group->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $group->is_active ? __('Aktif') : __('Nonaktif') }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $group->mikrotik_sync_status->badgeClasses() }}">
                                    {{ $group->mikrotik_sync_status->label() }}
                                </span>
                                @if ($group->mikrotik_sync_status->value === 'failed' && $group->mikrotik_sync_error)
                                    <p class="text-xs text-red-600 mt-1 max-w-xs truncate" title="{{ $group->mikrotik_sync_error }}">{{ $group->mikrotik_sync_error }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-right space-x-2 whitespace-nowrap">
                                @if ($canManage)
                                    <button wire:click="edit({{ $group->id }})" class="text-primary hover:underline">{{ __('Edit') }}</button>
                                    @if ($group->mikrotik_sync_status->value === 'failed')
                                        <button wire:click="resyncGroup({{ $group->id }})" class="text-primary hover:underline">{{ __('Sync Ulang') }}</button>
                                    @endif
                                    <button wire:click="deleteGroup({{ $group->id }})" wire:confirm="{{ __('Hapus grup profil ini?') }}" class="text-red-600 hover:underline">
                                        {{ __('Hapus') }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada grup profil.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $groups->links() }}
    </div>
</div>
