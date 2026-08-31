{{--
    v0.14.4 — same conditional wire:poll pattern as customer-ip-pool-index/
    network-profile-group-index, reused verbatim.
--}}
<div class="p-6 max-w-6xl mx-auto" @if ($hasPendingSync) wire:poll.5s="$refresh" @endif>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Profil Hotspot') }}</h1>

        @if ($canManage)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Profil Hotspot Baru') }}
            </button>
        @endif
    </div>

    @if ($showCreateForm)
        <form wire:submit="createPackage" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Grup Profil (Hotspot)') }}</label>
                    {{-- .live so the disabled-Simpan-button check below
                         reacts immediately the moment Grup Profil is
                         picked. --}}
                    <select wire:model.live="networkProfileGroupId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">{{ __('-- Pilih Grup Profil --') }}</option>
                        @foreach ($groupOptions as $groupOption)
                            <option value="{{ $groupOption->id }}">{{ $groupOption->name }} ({{ $groupOption->nas->name ?? '-' }})</option>
                        @endforeach
                    </select>
                    @error('networkProfileGroupId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Bandwidth Profile') }}</label>
                    <select wire:model="bandwidthProfileId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="">{{ __('-- Pilih Bandwidth Profile --') }}</option>
                        @foreach ($bandwidthProfileOptions as $bwOption)
                            <option value="{{ $bwOption->id }}">{{ $bwOption->name }}</option>
                        @endforeach
                    </select>
                    @error('bandwidthProfileId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Nama Paket') }}</label>
                    <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Harga Modal') }}</label>
                    <input type="number" step="0.01" wire:model="costPrice" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('costPrice') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Harga Jual') }}</label>
                    <input type="number" step="0.01" wire:model="sellPrice" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('sellPrice') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Harga Promo (opsional)') }}</label>
                    <input type="number" step="0.01" wire:model="promoPrice" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('promoPrice') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('PPN (%)') }}</label>
                    <input type="number" step="0.01" wire:model="taxPercent" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('taxPercent') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Tipe Profil') }}</label>
                    <select wire:model.live="profileType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="unlimited">{{ __('Unlimited') }}</option>
                        <option value="limited">{{ __('Limited') }}</option>
                    </select>
                    @error('profileType') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                @if ($profileType === 'limited')
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Batasan') }}</label>
                        <select wire:model.live="limitType" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">{{ __('-- Pilih --') }}</option>
                            <option value="time_base">{{ __('Time Base') }}</option>
                            <option value="quota_base">{{ __('Quota Base') }}</option>
                        </select>
                        @error('limitType') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Masa Aktif') }}</label>
                        <div class="mt-1 flex gap-2">
                            <input type="number" min="1" wire:model="activeDurationValue" class="block w-1/2 rounded-md border-gray-300 shadow-sm">
                            <select wire:model="activeDurationUnit" class="block w-1/2 rounded-md border-gray-300 shadow-sm">
                                <option value="minute">{{ __('Menit') }}</option>
                                <option value="hour">{{ __('Jam') }}</option>
                                <option value="day">{{ __('Hari') }}</option>
                                <option value="month">{{ __('Bulan') }}</option>
                            </select>
                        </div>
                        @error('activeDurationValue') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                        @error('activeDurationUnit') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>

            @if ($profileType === 'limited' && $limitType === 'quota_base')
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Kuota') }}</label>
                        <input type="number" step="0.01" min="0.01" wire:model="quotaValue" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        @error('quotaValue') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">{{ __('Satuan Data') }}</label>
                        <select wire:model="quotaUnit" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="mb">{{ __('MB') }}</option>
                            <option value="gb">{{ __('GB') }}</option>
                        </select>
                        @error('quotaUnit') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Shared Users') }}</label>
                    <input type="number" min="1" wire:model="sharedUsers" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('sharedUsers') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Prioritas') }}</label>
                    <select wire:model="priority" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        @foreach ($priorityOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('priority') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Periode Login — Hari') }}</label>
                <div class="flex flex-wrap gap-3">
                    @foreach ($dayOptions as $day)
                        <label class="flex items-center gap-1 text-sm text-gray-700">
                            <input type="checkbox" wire:model="loginDays" value="{{ $day }}"> {{ $dayLabels[$day] }}
                        </label>
                    @endforeach
                </div>
                <p class="text-xs text-gray-500 mt-1">{{ __('Kosongkan semua untuk mengizinkan login setiap hari.') }}</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Jam Mulai Login (opsional)') }}</label>
                    <input type="time" wire:model="loginStartTime" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('loginStartTime') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Jam Selesai Login (opsional)') }}</label>
                    <input type="time" wire:model="loginEndTime" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('loginEndTime') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="flex flex-wrap gap-6">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="visibleToReseller"> {{ __('Dapat diakses Operator/Reseller') }}
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="showInVoucherForm"> {{ __('Tampilkan di Form Beli e-Voucher') }}
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="isActive"> {{ __('Aktif') }}
                </label>
            </div>

            {{-- v0.14.4 amendment — Grup Profil (yang menentukan NAS
                 implisit) wajib dipilih dulu sebelum Simpan bisa diklik,
                 defense-in-depth di atas validasi backend 'required' yang
                 sudah ada (baseRules()). --}}
            <button type="submit" @if (! $networkProfileGroupId) disabled @endif class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-green-600">
                {{ __('Simpan') }}
            </button>
            @if (! $networkProfileGroupId)
                <p class="text-xs text-amber-600 mt-1">{{ __('Pilih Grup Profil terlebih dahulu sebelum menyimpan.') }}</p>
            @endif
        </form>
    @endif

    <div class="mb-4 flex flex-col md:flex-row gap-3">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama paket...') }}"
            class="w-full md:flex-1 rounded-md border-gray-300 shadow-sm"
        >
        <select wire:model.live="filterGroupId" class="w-full md:flex-1 rounded-md border-gray-300 shadow-sm">
            <option value="">{{ __('Semua Grup Profil') }}</option>
            @foreach ($groupOptions as $groupOption)
                <option value="{{ $groupOption->id }}">{{ $groupOption->name }}</option>
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
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Grup Profil') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Bandwidth') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Tipe') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Harga Jual') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Sync Router') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($packages as $package)
                    <tr wire:key="package-{{ $package->id }}">
                        @if ($editingPackageId === $package->id)
                            <td colspan="8" class="px-4 py-3">
                                <form wire:submit="updatePackage" class="space-y-3">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <select wire:model="editNetworkProfileGroupId" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @foreach ($groupOptions as $groupOption)
                                                <option value="{{ $groupOption->id }}">{{ $groupOption->name }}</option>
                                            @endforeach
                                        </select>
                                        <select wire:model="editBandwidthProfileId" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @foreach ($bandwidthProfileOptions as $bwOption)
                                                <option value="{{ $bwOption->id }}">{{ $bwOption->name }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" wire:model="editName" placeholder="{{ __('Nama Paket') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                    </div>
                                    @error('editNetworkProfileGroupId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @error('editBandwidthProfileId') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @error('editName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror

                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                        <input type="number" step="0.01" wire:model="editCostPrice" placeholder="{{ __('Harga Modal') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        <input type="number" step="0.01" wire:model="editSellPrice" placeholder="{{ __('Harga Jual') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        <input type="number" step="0.01" wire:model="editPromoPrice" placeholder="{{ __('Harga Promo') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        <input type="number" step="0.01" wire:model="editTaxPercent" placeholder="{{ __('PPN (%)') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                    </div>
                                    @error('editCostPrice') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @error('editSellPrice') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @error('editTaxPercent') <span class="text-xs text-red-600">{{ $message }}</span> @enderror

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <select wire:model.live="editProfileType" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            <option value="unlimited">{{ __('Unlimited') }}</option>
                                            <option value="limited">{{ __('Limited') }}</option>
                                        </select>
                                        @if ($editProfileType === 'limited')
                                            <select wire:model.live="editLimitType" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                                <option value="">{{ __('-- Pilih Batasan --') }}</option>
                                                <option value="time_base">{{ __('Time Base') }}</option>
                                                <option value="quota_base">{{ __('Quota Base') }}</option>
                                            </select>
                                            <div class="flex gap-2">
                                                <input type="number" min="1" wire:model="editActiveDurationValue" class="block w-1/2 rounded-md border-gray-300 shadow-sm text-sm">
                                                <select wire:model="editActiveDurationUnit" class="block w-1/2 rounded-md border-gray-300 shadow-sm text-sm">
                                                    <option value="minute">{{ __('Menit') }}</option>
                                                    <option value="hour">{{ __('Jam') }}</option>
                                                    <option value="day">{{ __('Hari') }}</option>
                                                    <option value="month">{{ __('Bulan') }}</option>
                                                </select>
                                            </div>
                                        @endif
                                    </div>
                                    @error('editLimitType') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @error('editActiveDurationValue') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @error('editActiveDurationUnit') <span class="text-xs text-red-600">{{ $message }}</span> @enderror

                                    @if ($editProfileType === 'limited' && $editLimitType === 'quota_base')
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <input type="number" step="0.01" min="0.01" wire:model="editQuotaValue" placeholder="{{ __('Kuota') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            <select wire:model="editQuotaUnit" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                                <option value="mb">{{ __('MB') }}</option>
                                                <option value="gb">{{ __('GB') }}</option>
                                            </select>
                                        </div>
                                        @error('editQuotaValue') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        @error('editQuotaUnit') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    @endif

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <input type="number" min="1" wire:model="editSharedUsers" placeholder="{{ __('Shared Users') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        <select wire:model="editPriority" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @foreach ($priorityOptions as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <p class="text-xs font-medium text-gray-700 mb-1">{{ __('Periode Login — Hari') }}</p>
                                        <div class="flex flex-wrap gap-3">
                                            @foreach ($dayOptions as $day)
                                                <label class="flex items-center gap-1 text-xs text-gray-700">
                                                    <input type="checkbox" wire:model="editLoginDays" value="{{ $day }}"> {{ $dayLabels[$day] }}
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <input type="time" wire:model="editLoginStartTime" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        <input type="time" wire:model="editLoginEndTime" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                    </div>
                                    @error('editLoginEndTime') <span class="text-xs text-red-600">{{ $message }}</span> @enderror

                                    <div class="flex flex-wrap gap-4">
                                        <label class="flex items-center gap-2 text-xs text-gray-700">
                                            <input type="checkbox" wire:model="editVisibleToReseller"> {{ __('Dapat diakses Operator/Reseller') }}
                                        </label>
                                        <label class="flex items-center gap-2 text-xs text-gray-700">
                                            <input type="checkbox" wire:model="editShowInVoucherForm"> {{ __('Tampilkan di Form Beli e-Voucher') }}
                                        </label>
                                        <label class="flex items-center gap-2 text-xs text-gray-700">
                                            <input type="checkbox" wire:model="editIsActive"> {{ __('Aktif') }}
                                        </label>
                                    </div>

                                    <div class="flex gap-2">
                                        <button type="submit" class="text-sm px-3 py-1.5 bg-green-600 text-white rounded-md hover:bg-green-700">{{ __('Simpan') }}</button>
                                        <button type="button" wire:click="cancelEdit" class="text-sm px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Batal') }}</button>
                                    </div>
                                </form>
                            </td>
                        @else
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $package->name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $package->networkProfileGroup->name ?? '-' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $package->bandwidthProfile->name ?? '-' }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $package->profile_type->label() }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ number_format((float) $package->sell_price, 2) }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $package->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $package->is_active ? __('Aktif') : __('Nonaktif') }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $package->mikrotik_sync_status->badgeClasses() }}">
                                    {{ $package->mikrotik_sync_status->label() }}
                                </span>
                                {{-- v0.14.4 amendment — surfaced regardless of status, not just
                                     'failed'. A mid-retry attempt (still 'pending', up to ~7.5
                                     minutes across 3 tries) already has a real error message
                                     stored the moment its first attempt fails — hiding it until
                                     the FINAL attempt made a genuinely-failing sync look
                                     indistinguishable from "stuck Pending with no explanation". --}}
                                @if ($package->mikrotik_sync_error)
                                    <p class="text-xs text-red-600 mt-1 max-w-xs truncate" title="{{ $package->mikrotik_sync_error }}">
                                        @if ($package->mikrotik_sync_status->value !== 'failed')
                                            {{ __('Percobaan terakhir gagal, akan dicoba ulang:') }}
                                        @endif
                                        {{ $package->mikrotik_sync_error }}
                                    </p>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-sm text-right space-x-2 whitespace-nowrap">
                                @if ($canManage)
                                    <button wire:click="edit({{ $package->id }})" class="text-primary hover:underline">{{ __('Edit') }}</button>
                                    @if ($package->mikrotik_sync_status->value === 'failed')
                                        <button wire:click="resyncPackage({{ $package->id }})" class="text-primary hover:underline">{{ __('Sync Ulang') }}</button>
                                    @endif
                                    <button wire:click="deletePackage({{ $package->id }})" wire:confirm="{{ __('Hapus profil hotspot ini?') }}" class="text-red-600 hover:underline">
                                        {{ __('Hapus') }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada profil hotspot.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $packages->links() }}
    </div>
</div>
