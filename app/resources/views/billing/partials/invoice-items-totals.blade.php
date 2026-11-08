{{-- v0.13.5.2 — SATU sumber angka invoice (line items + totals) dipakai
     bersama oleh browser-print (invoice-print-standard) dan PDF server-side
     (invoice-pdf). Semua angka dibaca dari field tersimpan Invoice / lineItems
     — tidak ada perhitungan ganda di dua tempat. Kelas CSS (.items/.totals)
     didefinisikan di <style> masing-masing view induk. --}}
@php
    /** @var \App\Models\Invoice $invoice */
    $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
    $taxRate = (float) $invoice->subtotal > 0
        ? round((float) $invoice->tax_total / (float) $invoice->subtotal * 100, 2)
        : 0;
@endphp
<table class="items">
    <thead>
        <tr>
            <th style="width:50%">Deskripsi</th>
            <th class="num">Qty</th>
            <th class="num">Harga</th>
            <th class="num">Jumlah</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($invoice->lineItems as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="num">{{ (int) $item->quantity }}</td>
                <td class="num">{{ $rp($item->unit_price) }}</td>
                <td class="num">{{ $rp($item->line_total) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Tidak ada item.</td></tr>
        @endforelse
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal (DPP)</td><td>{{ $rp($invoice->subtotal) }}</td></tr>
    <tr><td>PPN ({{ rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') }}%)</td><td>{{ $rp($invoice->tax_total) }}</td></tr>
    <tr class="grand"><td>Total</td><td>{{ $rp($invoice->grand_total) }}</td></tr>
</table>
