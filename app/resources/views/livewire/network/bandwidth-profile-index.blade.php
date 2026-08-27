<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Bandwidth Profile') }}</h1>

        @if ($canManage)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Profile Baru') }}
            </button>
        @endif
    </div>

    @if ($showCreateForm)
        <form wire:submit="createProfile" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nama') }}</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Satuan') }}</label>
                <select wire:model="unit" class="mt-1 block w-full max-w-xs rounded-md border-gray-300 shadow-sm">
                    <option value="Kbps">Kbps</option>
                    <option value="Mbps">Mbps</option>
                </select>
                <p class="text-xs text-gray-500 mt-1">{{ __('Semua nilai di bawah dalam satuan yang dipilih ini — dikonversi ke Kbps otomatis saat disimpan.') }}</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Upload Min') }}</label>
                    <input type="text" wire:model="uploadMin" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('uploadMin') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Upload Max') }}</label>
                    <input type="text" wire:model="uploadMax" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('uploadMax') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Download Min') }}</label>
                    <input type="text" wire:model="downloadMin" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('downloadMin') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Download Max') }}</label>
                    <input type="text" wire:model="downloadMax" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('downloadMax') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
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

    <div class="mb-4">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama profile...') }}"
            class="w-full rounded-md border-gray-300 shadow-sm"
        >
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer" wire:click="sortByColumn('name')">
                        {{ __('Nama') }} @if ($sortBy === 'name') {{ $sortDir === 'asc' ? '▲' : '▼' }} @endif
                    </th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Upload') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Download') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($profiles as $profile)
                    <tr wire:key="profile-{{ $profile->id }}">
                        @if ($editingProfileId === $profile->id)
                            <td colspan="5" class="px-4 py-3">
                                <form wire:submit="updateProfile" class="space-y-3">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <input type="text" wire:model="editName" placeholder="{{ __('Nama') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                        <select wire:model="editUnit" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            <option value="Kbps">Kbps</option>
                                            <option value="Mbps">Mbps</option>
                                        </select>
                                        <label class="flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" wire:model="editIsActive"> {{ __('Aktif') }}
                                        </label>
                                    </div>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                        <div>
                                            <input type="text" wire:model="editUploadMin" placeholder="{{ __('Upload Min') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @error('editUploadMin') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                        <div>
                                            <input type="text" wire:model="editUploadMax" placeholder="{{ __('Upload Max') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @error('editUploadMax') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                        <div>
                                            <input type="text" wire:model="editDownloadMin" placeholder="{{ __('Download Min') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @error('editDownloadMin') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                        <div>
                                            <input type="text" wire:model="editDownloadMax" placeholder="{{ __('Download Max') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @error('editDownloadMax') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="submit" class="text-sm px-3 py-1.5 bg-green-600 text-white rounded-md hover:bg-green-700">{{ __('Simpan') }}</button>
                                        <button type="button" wire:click="cancelEdit" class="text-sm px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Batal') }}</button>
                                    </div>
                                </form>
                            </td>
                        @else
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $profile->name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $profile->upload_min_display }} - {{ $profile->upload_max_display }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $profile->download_min_display }} - {{ $profile->download_max_display }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $profile->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $profile->is_active ? __('Aktif') : __('Nonaktif') }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm text-right space-x-2 whitespace-nowrap">
                                @if ($canManage)
                                    <button wire:click="edit({{ $profile->id }})" class="text-primary hover:underline">{{ __('Edit') }}</button>
                                    <button wire:click="deleteProfile({{ $profile->id }})" wire:confirm="{{ __('Hapus bandwidth profile ini?') }}" class="text-red-600 hover:underline">
                                        {{ __('Hapus') }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada bandwidth profile.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $profiles->links() }}
    </div>
</div>
