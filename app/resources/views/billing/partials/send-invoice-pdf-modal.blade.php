{{--
    v0.13.5.3 — modal konfirmasi "Kirim PDF via WhatsApp".
    Dipakai identik oleh InvoiceIndex DAN CustomerShow (property/method sama:
    $sendPdfInvoiceId, $sendPdfPreview, $sendPdfError, confirmSendPdf/sendPdf/
    cancelSendPdf). Penegakan sesungguhnya ada di InvoicePdfSendService —
    modal ini hanya menampilkan & mengonfirmasi.
--}}
@if ($sendPdfInvoiceId !== null)
    @php($p = $sendPdfPreview)
    <div class="fixed inset-0 z-[1100] flex items-center justify-center bg-black/40 p-4"
        wire:click.self="cancelSendPdf">
        <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
            <div class="border-b border-gray-200 px-5 py-3">
                <h3 class="text-base font-semibold text-gray-800">{{ __('Kirim PDF Invoice via WhatsApp') }}</h3>
            </div>

            <div class="px-5 py-4 space-y-3 text-sm">
                @if (($p['test_mode'] ?? false))
                    <div class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-red-700">
                        <span class="font-semibold">{{ __('MODE UJI:') }}</span>
                        {{ __('akan dikirim ke') }} <span class="font-mono">{{ $p['recipient_display'] ?? '—' }}</span>,
                        <span class="font-semibold">{{ __('BUKAN ke pelanggan.') }}</span>
                    </div>
                @endif

                <dl class="grid grid-cols-3 gap-y-1">
                    <dt class="text-gray-500">{{ __('Pelanggan') }}</dt>
                    <dd class="col-span-2 text-gray-800">{{ $p['customer_name'] ?? '—' }}</dd>

                    <dt class="text-gray-500">{{ __('Nomor tujuan') }}</dt>
                    <dd class="col-span-2 font-mono text-gray-800">{{ $p['recipient_display'] ?: '—' }}</dd>

                    <dt class="text-gray-500">{{ __('Invoice') }}</dt>
                    <dd class="col-span-2 text-gray-800">{{ $p['invoice_number'] ?? '—' }}</dd>

                    <dt class="text-gray-500">{{ __('Total') }}</dt>
                    <dd class="col-span-2 text-gray-800">{{ $p['total'] ?? '—' }}</dd>

                    <dt class="text-gray-500">{{ __('Jatuh tempo') }}</dt>
                    <dd class="col-span-2 text-gray-800">{{ $p['due_date'] ?? '—' }}</dd>
                </dl>

                @if (! empty($p['already_sent_at']))
                    <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-amber-800">
                        {{ __('Invoice ini sudah pernah terkirim ke pelanggan pada') }}
                        {{ \Illuminate\Support\Carbon::parse($p['already_sent_at'])->translatedFormat('d M Y H:i') }}.
                        {{ __('Pakai "Kirim Ulang" bila memang perlu.') }}
                    </div>
                @endif

                @if ($sendPdfError !== null)
                    <div class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-red-700">{{ $sendPdfError }}</div>
                @endif
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-3">
                <button type="button" wire:click="cancelSendPdf"
                    class="px-3 py-1.5 text-sm text-gray-600 hover:underline">{{ __('Batal') }}</button>

                @if (! empty($p['already_sent_at']))
                    <button type="button" wire:click="sendPdf(true)" wire:loading.attr="disabled"
                        class="px-4 py-1.5 text-sm bg-amber-600 text-white rounded-md hover:opacity-90">
                        {{ __('Kirim Ulang') }}
                    </button>
                @else
                    <button type="button" wire:click="sendPdf(false)" wire:loading.attr="disabled"
                        class="px-4 py-1.5 text-sm bg-primary text-white rounded-md hover:opacity-90">
                        {{ __('Kirim') }}
                    </button>
                @endif
            </div>
        </div>
    </div>
@endif
