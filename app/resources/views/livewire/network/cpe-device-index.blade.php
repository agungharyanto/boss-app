<div class="p-6 max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-800">{{ __('Perangkat CPE') }}</h1>
    </div>

    <div class="mb-4">
        <input
            type="text" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Cari nama pelanggan atau nomor serial...') }}"
            class="w-full rounded-md border-gray-300 shadow-sm"
        >
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Pelanggan') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Manufacturer / Model') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Serial Number') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Last Inform') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($devices as $device)
                    <tr wire:key="cpe-device-{{ $device->id }}">
                        <td class="px-4 py-2 text-sm text-gray-800">
                            {{ $device->customer?->name ?? '—' }}
                            @if ($device->reseller)
                                <span class="block text-xs text-gray-400">{{ $device->reseller->name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            {{ $device->manufacturer ?? '—' }}
                            @if ($device->model_name)
                                <span class="block text-xs text-gray-400">{{ $device->model_name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600 font-mono">{{ $device->serial_number }}</td>
                        <td class="px-4 py-2 text-sm">
                            @php
                                $statusColor = match ($device->status->value) {
                                    'online' => 'bg-green-100 text-green-700',
                                    'offline' => 'bg-red-100 text-red-700',
                                    default => 'bg-yellow-100 text-yellow-700',
                                };
                            @endphp
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $statusColor }}">
                                {{ $device->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            {{ $device->last_inform_at?->diffForHumans() ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada perangkat CPE.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $devices->links() }}
    </div>
</div>
