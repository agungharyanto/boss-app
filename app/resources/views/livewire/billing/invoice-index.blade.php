<div class="p-6 max-w-6xl mx-auto">
    <h1 class="text-2xl font-semibold text-gray-800 mb-6">{{ __('Invoices') }}</h1>

    @if ($transitionError)
        <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded-md">
            {{ $transitionError }}
        </div>
    @endif

    <div class="mb-4">
        <select wire:model.live="statusFilter" class="rounded-md border-gray-300 shadow-sm">
            <option value="">{{ __('Semua Status') }}</option>
            @foreach (\App\Enums\InvoiceStatus::cases() as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('No. Invoice') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Periode') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Jatuh Tempo') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Total') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">{{ __('Aksi') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($invoices as $invoice)
                    <tr wire:key="invoice-{{ $invoice->id }}">
                        <td class="px-4 py-2 text-sm font-mono text-gray-800">{{ $invoice->invoice_number }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $invoice->customer?->name }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $invoice->period_start->toDateString() }} &ndash; {{ $invoice->period_end->toDateString() }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $invoice->due_date->toDateString() }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">Rp {{ number_format((float) $invoice->grand_total, 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-sm">
                            @php
                                $badgeClass = match ($invoice->status->value) {
                                    'paid' => 'bg-green-100 text-green-700',
                                    'pending' => 'bg-blue-100 text-blue-700',
                                    'overdue' => 'bg-red-100 text-red-700',
                                    'cancelled' => 'bg-gray-100 text-gray-500',
                                    default => 'bg-yellow-100 text-yellow-700',
                                };
                            @endphp
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $badgeClass }}">{{ $invoice->status->label() }}</span>
                        </td>
                        <td class="px-4 py-2 text-sm text-right space-x-2">
                            @if ($canManage)
                                @if ($invoice->status->canTransitionTo(\App\Enums\InvoiceStatus::Pending))
                                    <button wire:click="markPending({{ $invoice->id }})" class="text-primary hover:underline">{{ __('Issue') }}</button>
                                @endif
                                @if ($invoice->status->canTransitionTo(\App\Enums\InvoiceStatus::Paid))
                                    <button wire:click="markPaid({{ $invoice->id }})" class="text-green-600 hover:underline">{{ __('Tandai Lunas') }}</button>
                                @endif
                                @if ($invoice->status->canTransitionTo(\App\Enums\InvoiceStatus::Cancelled))
                                    <button
                                        wire:click="cancelInvoice({{ $invoice->id }})"
                                        wire:confirm="{{ __('Batalkan invoice ini?') }}"
                                        class="text-red-600 hover:underline"
                                    >
                                        {{ __('Batalkan') }}
                                    </button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada invoice.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $invoices->links() }}
    </div>
</div>
