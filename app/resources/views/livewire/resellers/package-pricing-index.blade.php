<div class="p-6 max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Package Pricing') }}</h1>

        @if ($canCreate)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Package Baru') }}
            </button>
        @endif
    </div>

    @if ($showCreateForm)
        <form wire:submit="save" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nama Package') }}</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Deskripsi') }}</label>
                <textarea wire:model="description" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Harga (Rp)') }}</label>
                <input type="number" step="0.01" wire:model="price" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('price') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="isCustom" class="rounded border-gray-300">
                {{ __('Bundle custom (bukan package standar)') }}
            </label>
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
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Harga') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Tipe') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($pricingList as $pricing)
                    <tr wire:key="pricing-{{ $pricing->id }}">
                        <td class="px-4 py-2 text-sm text-gray-800">{{ $pricing->name }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">Rp {{ number_format((float) $pricing->price, 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $pricing->is_custom ? __('Custom') : __('Standar') }}</td>
                        <td class="px-4 py-2 text-sm">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $pricing->status->value === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                                {{ $pricing->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm text-right space-x-2">
                            <button wire:click="edit({{ $pricing->id }})" class="text-primary hover:underline">{{ __('Edit') }}</button>
                            @if ($pricing->status->value === 'active')
                                <button
                                    wire:click="deactivate({{ $pricing->id }})"
                                    wire:confirm="{{ __('Nonaktifkan package ini?') }}"
                                    class="text-red-600 hover:underline"
                                >
                                    {{ __('Nonaktifkan') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada package pricing.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $pricingList->links() }}
    </div>
</div>
