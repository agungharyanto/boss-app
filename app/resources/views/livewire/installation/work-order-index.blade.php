<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-semibold text-gray-800 mb-6">{{ __('Work Order') }}</h1>

    @error('assign')
        <div class="mb-6 p-4 rounded-md border border-red-300 bg-red-50">
            <p class="text-sm text-red-700">{{ $message }}</p>
        </div>
    @enderror

    <div class="mb-4 flex items-center gap-3">
        <label class="text-sm font-medium text-gray-700">{{ __('Status') }}</label>
        <select wire:model.live="statusFilter" class="rounded-md border-gray-300 shadow-sm text-sm">
            <option value="">{{ __('Semua') }}</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('ID') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Nama Pelanggan') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Jenis Layanan') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Janji Kunjungan') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status Dispatch') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status WO') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Teknisi') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($workOrders as $workOrder)
                    <tr wire:key="wo-{{ $workOrder->id }}">
                        <td class="px-4 py-2 text-sm text-gray-800">#{{ $workOrder->id }}</td>
                        <td class="px-4 py-2 text-sm text-gray-800">{{ $workOrder->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $workOrder->subscription?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">
                            {{ $workOrder->scheduled_at?->translatedFormat('d M Y H:i') ?? '-' }}
                        </td>
                        <td class="px-4 py-2 text-sm">
                            @if ($workOrder->dispatched_at)
                                <span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">
                                    {{ __('Sudah keluar :date', ['date' => $workOrder->dispatched_at->translatedFormat('d M Y H:i')]) }}
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-500">
                                    {{ __('Belum keluar') }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $workOrder->status->label() }}</td>
                        <td class="px-4 py-2 text-sm">
                            @if ($workOrder->status->value === \App\Enums\WorkOrderStatus::Ready->value)
                                <select
                                    wire:change="assignTechnician({{ $workOrder->id }}, $event.target.value)"
                                    class="rounded-md border-gray-300 shadow-sm text-sm"
                                >
                                    <option value="">{{ __('Belum ditugaskan') }}</option>
                                    @foreach ($technicians as $technician)
                                        <option value="{{ $technician->id }}" @selected($workOrder->technician_id === $technician->id)>
                                            {{ $technician->name }}
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <span class="text-sm text-gray-600">{{ $workOrder->technician?->name ?? '—' }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-sm text-right">
                            <a href="{{ route('web.work-orders.show', $workOrder) }}" class="text-primary hover:underline">
                                {{ __('Lihat Detail') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada Work Order.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $workOrders->links() }}
    </div>
</div>
