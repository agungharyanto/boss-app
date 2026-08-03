<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Tax Components') }}</h1>

        @if ($canCreate)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Tax Component Baru') }}
            </button>
        @endif
    </div>

    @if ($showCreateForm)
        <form wire:submit="createComponent" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Code') }}</label>
                    <input type="text" wire:model="code" placeholder="PPN" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('code') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Nama Tampilan') }}</label>
                    <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Tipe') }}</label>
                    <select wire:model="type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <option value="percentage">{{ __('Percentage') }}</option>
                        <option value="fixed">{{ __('Fixed') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Rate') }}</label>
                    <input type="number" step="0.0001" wire:model="rate" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('rate') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('Berlaku Sejak') }}</label>
                    <input type="date" wire:model="effective_from" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    @error('effective_from') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Deskripsi') }}</label>
                <textarea wire:model="description" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
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
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Code') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nama') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Tipe') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Rate') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Berlaku') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($components as $component)
                    <tr wire:key="tax-component-{{ $component->id }}">
                        <td class="px-4 py-2 text-sm font-medium text-gray-800">{{ $component->code }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $component->name }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $component->type->label() }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            {{ $component->type->value === 'percentage' ? $component->rate . '%' : 'Rp ' . number_format((float) $component->rate, 0, ',', '.') }}
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            {{ $component->effective_from->toDateString() }}
                            @if ($component->effective_to)
                                &ndash; {{ $component->effective_to->toDateString() }}
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $component->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                {{ $component->is_active ? __('Aktif') : __('Nonaktif') }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm text-right space-x-2">
                            <button wire:click="startUpdateRate({{ $component->id }})" class="text-primary hover:underline">{{ __('Update Rate') }}</button>
                            <button wire:click="toggleActive({{ $component->id }})" class="text-gray-600 hover:underline">
                                {{ $component->is_active ? __('Nonaktifkan') : __('Aktifkan') }}
                            </button>
                        </td>
                    </tr>
                    @if ($updatingRateFor === $component->id)
                        <tr>
                            <td colspan="7" class="px-4 py-3 bg-gray-50">
                                <form wire:submit="submitUpdateRate" class="flex gap-3 items-end flex-wrap">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">{{ __('Rate Baru') }}</label>
                                        <input type="number" step="0.0001" wire:model="newRate" class="mt-1 rounded-md border-gray-300 shadow-sm">
                                        @error('newRate') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">{{ __('Berlaku Efektif Sejak') }}</label>
                                        <input type="date" wire:model="newRateEffectiveFrom" class="mt-1 rounded-md border-gray-300 shadow-sm">
                                        @error('newRateEffectiveFrom') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                                    </div>
                                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">{{ __('Terapkan') }}</button>
                                    <button type="button" wire:click="cancelUpdateRate" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">{{ __('Batal') }}</button>
                                </form>
                                <p class="mt-2 text-xs text-gray-500">
                                    {{ __('Tarif lama akan otomatis ditutup (effective_to) sehari sebelum tanggal efektif baru — riwayat tarif tetap tersimpan.') }}
                                </p>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada tax component.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $components->links() }}
    </div>
</div>
