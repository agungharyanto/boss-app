@php
    /** @var \App\Models\Invoice $invoice */
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $taxRate = (float) $invoice->subtotal > 0
        ? round((float) $invoice->tax_total / (float) $invoice->subtotal * 100, 2)
        : 0;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            font-size: 12px;
            color: #1a1a1a;
            background: #f3f4f6;
        }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 12px auto;
            padding: 18mm 16mm;
            background: #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,.15);
        }
        .toolbar {
            width: 210mm; margin: 12px auto 0; text-align: right;
        }
        .toolbar button {
            font: inherit; padding: 8px 16px; cursor: pointer;
            border: 1px solid #2563eb; background: #2563eb; color: #fff; border-radius: 6px;
        }
        h1 { font-size: 22px; letter-spacing: 2px; margin: 0; color: #111; }
        .muted { color: #6b7280; }
        .row { display: flex; justify-content: space-between; gap: 24px; }
        .head { margin-bottom: 24px; }
        .company-name { font-size: 15px; font-weight: 700; }
        .meta td { padding: 2px 0; }
        .meta td:first-child { color: #6b7280; padding-right: 16px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table.items th, table.items td { padding: 8px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        table.items th { background: #f9fafb; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; }
        table.items td.num, table.items th.num { text-align: right; }
        .totals { margin-top: 16px; margin-left: auto; width: 260px; }
        .totals td { padding: 5px 0; }
        .totals td:last-child { text-align: right; }
        .totals tr.grand td { border-top: 2px solid #111; font-weight: 700; font-size: 14px; padding-top: 8px; }
        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 999px; font-weight: 700;
            font-size: 11px; text-transform: uppercase; letter-spacing: .5px;
        }
        .badge.paid { background: #dcfce7; color: #166534; }
        .badge.other { background: #fef9c3; color: #854d0e; }
        .footer { margin-top: 48px; color: #6b7280; font-size: 11px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .paid-stamp {
            display: inline-block; margin-top: 8px; transform: rotate(-8deg);
            border: 3px solid #16a34a; color: #16a34a; font-weight: 800; font-size: 20px;
            letter-spacing: 3px; padding: 4px 18px; border-radius: 6px; opacity: .85;
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; padding: 12mm 14mm; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    </div>

    <div class="sheet">
        <div class="row head">
            <div>
                <div class="company-name">{{ $company['name'] }}</div>
                @if ($company['address'])<div class="muted">{{ $company['address'] }}</div>@endif
                @if ($company['phone'])<div class="muted">Telp: {{ $company['phone'] }}</div>@endif
                @if ($company['email'])<div class="muted">{{ $company['email'] }}</div>@endif
            </div>
            <div style="text-align:right">
                <h1>INVOICE</h1>
                <div class="muted">{{ $invoice->invoice_number }}</div>
                <div style="margin-top:6px">
                    <span class="badge {{ $invoice->status->value === 'paid' ? 'paid' : 'other' }}">
                        {{ $invoice->status->label() }}
                    </span>
                </div>
            </div>
        </div>

        <div class="row">
            <div>
                <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">Ditagihkan kepada</div>
                <div style="font-weight:700;margin-top:2px">{{ $invoice->customer?->name ?? '—' }}</div>
                @if ($invoice->customer?->address)<div class="muted">{{ $invoice->customer->address }}</div>@endif
                @if ($invoice->customer?->phone_number)<div class="muted">{{ $invoice->customer->phone_number }}</div>@endif
            </div>
            <div>
                <table class="meta">
                    <tr><td>Tanggal terbit</td><td>{{ $invoice->generated_at?->translatedFormat('d M Y') }}</td></tr>
                    <tr><td>Jatuh tempo</td><td>{{ $invoice->due_date?->translatedFormat('d M Y') }}</td></tr>
                    <tr><td>Periode</td><td>{{ $invoice->period_start?->translatedFormat('d M Y') }} – {{ $invoice->period_end?->translatedFormat('d M Y') }}</td></tr>
                    @if ($invoice->paid_at)
                        <tr><td>Dibayar</td><td>{{ $invoice->paid_at?->translatedFormat('d M Y H:i') }}</td></tr>
                    @endif
                </table>
            </div>
        </div>

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

        @if ($invoice->status->value === 'paid')
            <div class="paid-stamp">LUNAS</div>
        @endif

        <div class="footer">
            {{ $company['footer_note'] }}
        </div>
    </div>

    @if ($autoPrint)
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
    @endif
</body>
</html>
