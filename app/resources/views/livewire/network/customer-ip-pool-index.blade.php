{{--
    v0.14.2.2 — wire:poll.5s is ONLY present in the rendered HTML while
    $hasPendingSync is true (computed in render() from the CURRENTLY
    DISPLAYED page's own rows). Livewire's poll mechanism is tied to the
    attribute's presence on this element: the moment a render omits it
    (every visible row has moved to Synced/Gagal), the underlying interval
    is torn down automatically — no manual stop/start logic needed, this
    is Livewire's own documented conditional-polling pattern. No
    wire:loading exclusion was added here — this component has no
    wire:loading indicator anywhere (confirmed by grep before writing
    this), so there's nothing for a 5s poll to make flicker.
--}}
<div class="p-6 max-w-6xl mx-auto" @if ($hasPendingSync) wire:poll.5s="$refresh" @endif>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('IP Pool Pelanggan') }}</h1>

        @if ($canManage)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Pool Baru') }}
            </button>
        @endif
    </div>

    @if ($showCreateForm)
        <form wire:submit="createPool" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('NAS') }}</label>
                <select wire:model="nasId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">{{ __('-- Pilih NAS --') }}</option>
                    @foreach ($nasOptions as $nasOption)
                        <option value="{{ $nasOption->id }}">{{ $nasOption->name }}</option>
                    @endforeach
                </select>
                @error('nasId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nama Pool') }}</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div>
                {{-- v0.14.3.1 — wajib diisi, tidak ada default dipilih di
                     sini, supaya admin selalu sadar menentukan tipe
                     pemakaian pool yang baru dibuat. --}}
                <label class="block text-sm font-medium text-gray-700">{{ __('Tipe Pemakaian') }}</label>
                <select wire:model="usageType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <option value="">{{ __('-- Pilih Tipe --') }}</option>
                    <option value="ppp">{{ __('PPP') }}</option>
                    <option value="hotspot">{{ __('Hotspot') }}</option>
                    <option value="general">{{ __('Umum') }}</option>
                </select>
                @error('usageType') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Network Address (CIDR)') }}</label>
                <input type="text" wire:model="networkAddress" placeholder="192.168.10.0/24" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('networkAddress') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Gateway IP') }}</label>
                    <input type="text" wire:model="gatewayIp" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('gatewayIp') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Range Start') }}</label>
                    <input type="text" wire:model="rangeStart" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('rangeStart') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Range End') }}</label>
                    <input type="text" wire:model="rangeEnd" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('rangeEnd') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
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
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="isActive"> {{ __('Aktif') }}
            </label>

            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                {{ __('Simpan') }}
            </button>
        </form>
    @endif

    <div class="mb-4 flex flex-col md:flex-row gap-3">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama pool...') }}"
            class="w-full md:flex-1 rounded-md border-gray-300 shadow-sm"
        >
        <select wire:model.live="filterNasId" class="w-full md:flex-1 rounded-md border-gray-300 shadow-sm">
            <option value="">{{ __('Semua NAS') }}</option>
            @foreach ($nasOptions as $nasOption)
                <option value="{{ $nasOption->id }}">{{ $nasOption->name }}</option>
            @endforeach
        </select>
        {{-- v0.14.2.2 — manual refresh, plain Livewire AJAX ($refresh
             re-renders with fresh data, no method needed) — never a
             full-page/URL navigation. --}}
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
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Network') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Range') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Sync Router') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($pools as $pool)
                    <tr wire:key="pool-{{ $pool->id }}">
                        @if ($editingPoolId === $pool->id)
                            <td colspan="8" class="px-4 py-3">
                                <form wire:submit="updatePool" class="space-y-3">
                                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                                        <select wire:model="editNasId" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @foreach ($nasOptions as $nasOption)
                                                <option value="{{ $nasOption->id }}">{{ $nasOption->name }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" wire:model="editName" placeholder="{{ __('Nama Pool') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        <select wire:model="editUsageType" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            <option value="ppp">{{ __('PPP') }}</option>
                                            <option value="hotspot">{{ __('Hotspot') }}</option>
                                            <option value="general">{{ __('Umum') }}</option>
                                        </select>
                                        <input type="text" wire:model="editNetworkAddress" placeholder="192.168.10.0/24" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                    </div>
                                    @error('editNasId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @error('editName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @error('editUsageType') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @error('editNetworkAddress') <span class="text-xs text-red-600">{{ $message }}</span> @enderror

                                    <div class="grid grid-cols-3 gap-3">
                                        <div>
                                            <input type="text" wire:model="editGatewayIp" placeholder="{{ __('Gateway IP') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @error('editGatewayIp') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                        <div>
                                            <input type="text" wire:model="editRangeStart" placeholder="{{ __('Range Start') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @error('editRangeStart') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                        <div>
                                            <input type="text" wire:model="editRangeEnd" placeholder="{{ __('Range End') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @error('editRangeEnd') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <input type="text" wire:model="editDnsPrimary" placeholder="{{ __('DNS Primer') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        <input type="text" wire:model="editDnsSecondary" placeholder="{{ __('DNS Sekunder') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
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
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $pool->name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $pool->nas->name ?? '-' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $pool->usage_type->label() }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $pool->network_address }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $pool->range_start }} - {{ $pool->range_end }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $pool->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $pool->is_active ? __('Aktif') : __('Nonaktif') }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $pool->mikrotik_sync_status->badgeClasses() }}">
                                    {{ $pool->mikrotik_sync_status->label() }}
                                </span>
                                @if ($pool->mikrotik_sync_status->value === 'failed' && $pool->mikrotik_sync_error)
                                    <p class="text-xs text-red-600 mt-1 max-w-xs truncate" title="{{ $pool->mikrotik_sync_error }}">{{ $pool->mikrotik_sync_error }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-right space-x-2 whitespace-nowrap">
                                @if ($canManage)
                                    <button wire:click="edit({{ $pool->id }})" class="text-primary hover:underline">{{ __('Edit') }}</button>
                                    @if ($pool->mikrotik_sync_status->value === 'failed')
                                        <button wire:click="resyncPool({{ $pool->id }})" class="text-primary hover:underline">{{ __('Sync Ulang') }}</button>
                                    @endif
                                    <button wire:click="deletePool({{ $pool->id }})" wire:confirm="{{ __('Hapus customer IP pool ini?') }}" class="text-red-600 hover:underline">
                                        {{ __('Hapus') }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada customer IP pool.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $pools->links() }}
    </div>
</div>
