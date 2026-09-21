<div class="p-6 max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-800">{{ __('Tipe Alat') }}</h1>
            <p class="text-sm text-gray-500 mt-1">{{ __('Master data alat non-modem yang bisa dicatat dibawa saat klaim Work Order (Dropcore, Adapter, dll).') }}</p>
        </div>

        @if ($canManage)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Tipe Alat Baru') }}
            </button>
        @endif
    </div>

    @if ($showCreateForm)
        <form wire:submit="createToolType" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nama') }}</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Kategori (opsional)') }}</label>
                <input type="text" wire:model="category" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('category') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                {{ __('Simpan') }}
            </button>
        </form>
    @endif

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nama') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Kategori') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($toolTypes as $toolType)
                    <tr wire:key="tool-type-{{ $toolType->id }}">
                        @if ($editingToolTypeId === $toolType->id)
                            <td colspan="4" class="px-4 py-3">
                                <form wire:submit="updateToolType" class="space-y-3">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <div>
                                            <input type="text" wire:model="editName" placeholder="{{ __('Nama') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @error('editName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                        <div>
                                            <input type="text" wire:model="editCategory" placeholder="{{ __('Kategori (opsional)') }}" class="block w-full rounded-md border-gray-300 shadow-sm text-sm">
                                            @error('editCategory') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                        </div>
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="submit" class="text-sm px-3 py-1.5 bg-green-600 text-white rounded-md hover:bg-green-700">{{ __('Simpan') }}</button>
                                        <button type="button" wire:click="cancelEdit" class="text-sm px-3 py-1.5 border border-gray-300 rounded-md hover:bg-gray-50">{{ __('Batal') }}</button>
                                    </div>
                                </form>
                            </td>
                        @else
                            <td class="px-4 py-2 text-sm text-gray-800">{{ $toolType->name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600">{{ $toolType->category ?? '-' }}</td>
                            <td class="px-4 py-2 text-sm">
                                <span class="px-2 py-0.5 rounded-full text-xs {{ $toolType->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $toolType->is_active ? __('Aktif') : __('Nonaktif') }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-sm text-right space-x-2 whitespace-nowrap">
                                @if ($canManage)
                                    <button wire:click="edit({{ $toolType->id }})" class="text-primary hover:underline">{{ __('Edit') }}</button>
                                    <button
                                        wire:click="toggleActive({{ $toolType->id }})"
                                        wire:confirm="{{ $toolType->is_active ? __('Nonaktifkan tipe alat ini? Tidak akan muncul lagi di dropdown form klaim WO.') : __('Aktifkan lagi tipe alat ini?') }}"
                                        class="text-gray-600 hover:underline"
                                    >
                                        {{ $toolType->is_active ? __('Nonaktifkan') : __('Aktifkan') }}
                                    </button>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada tipe alat.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
