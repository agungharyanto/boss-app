<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Reseller') }}</h1>

        @if ($canCreate)
            <button
                wire:click="$set('showCreateForm', {{ $showCreateForm ? 'false' : 'true' }})"
                class="px-4 py-2 bg-primary text-white rounded-md hover:opacity-90"
            >
                {{ $showCreateForm ? __('Batal') : __('+ Reseller Baru') }}
            </button>
        @endif
    </div>

    @if ($showCreateForm)
        <form wire:submit="createReseller" class="mb-6 p-4 border border-gray-200 rounded-md bg-gray-50 space-y-3">
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Nama') }}</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Email') }}</label>
                <input type="email" wire:model="email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">{{ __('Telepon') }}</label>
                <input type="text" wire:model="phone" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                @error('phone') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                {{ __('Simpan') }}
            </button>
        </form>
    @endif

    <div class="mb-4">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama reseller...') }}"
            class="w-full rounded-md border-gray-300 shadow-sm"
        >
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nama') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Email') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($resellers as $reseller)
                    <tr wire:key="reseller-{{ $reseller->id }}">
                        <td class="px-4 py-2 text-sm text-gray-800">
                            <a href="{{ route('web.resellers.show', $reseller) }}" class="text-primary hover:underline">
                                {{ $reseller->name }}
                            </a>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $reseller->email ?? '—' }}</td>
                        <td class="px-4 py-2 text-sm">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $reseller->status->value === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $reseller->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm text-right">
                            @if ($reseller->status->value === 'active')
                                <button
                                    wire:click="suspendReseller({{ $reseller->id }})"
                                    wire:confirm="{{ __('Suspend reseller ini?') }}"
                                    class="text-red-600 hover:underline"
                                >
                                    {{ __('Suspend') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada reseller.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $resellers->links() }}
    </div>
</div>
