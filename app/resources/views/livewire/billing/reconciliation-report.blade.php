<div class="p-6 max-w-6xl mx-auto">
    <h1 class="text-2xl font-semibold text-gray-800 mb-2">{{ __('Payment Reconciliation') }}</h1>
    <p class="text-sm text-gray-500 mb-6">
        {{ __('Laporan audit read-only — tidak ada tombol perbaikan otomatis. Anomali perlu ditindaklanjuti manual.') }}
    </p>

    <h2 class="text-lg font-semibold text-gray-800 mb-3">{{ __('Invoice Paid vs Payment') }}</h2>
    <div class="overflow-x-auto border border-gray-200 rounded-md mb-8">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('No. Invoice') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Grand Total') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Payment Channel') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Paid At') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($reconciliation as $row)
                    <tr wire:key="recon-{{ $row['invoice']->id }}" class="{{ $row['is_anomaly'] ? 'bg-red-50' : '' }}">
                        <td class="px-4 py-2 text-sm font-mono text-gray-800">{{ $row['invoice']->invoice_number }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $row['invoice']->customer?->name }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">Rp {{ number_format((float) $row['invoice']->grand_total, 0, ',', '.') }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $row['payment'] ? \App\Models\PaymentGatewayChannel::labelFor($row['payment']->channel_type) : '—' }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $row['payment']?->paid_at?->toDateTimeString() ?? '—' }}</td>
                        <td class="px-4 py-2 text-sm">
                            @if ($row['is_anomaly'])
                                <span class="px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-700">{{ __('ANOMALY: no matching payment') }}</span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">{{ __('OK') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Belum ada invoice paid.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mb-8">
        {{ $paidInvoices->links() }}
    </div>

    <h2 class="text-lg font-semibold text-gray-800 mb-3">{{ __('Webhook Anomalies (non-applied)') }}</h2>
    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Event ID') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('External ID') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Hasil') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Signature Valid') }}</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Waktu') }}</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($anomalousWebhooks as $log)
                    <tr wire:key="webhook-log-{{ $log->id }}">
                        <td class="px-4 py-2 text-sm font-mono text-gray-800">{{ $log->xendit_event_id }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $log->payload['external_id'] ?? '—' }}</td>
                        <td class="px-4 py-2 text-sm">
                            <span class="px-2 py-0.5 rounded-full text-xs bg-yellow-100 text-yellow-700">{{ $log->processing_result->label() }}</span>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $log->signature_valid ? __('Ya') : __('Tidak') }}</td>
                        <td class="px-4 py-2 text-sm text-gray-600">{{ $log->created_at->toDateTimeString() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                            {{ __('Tidak ada anomali webhook.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
