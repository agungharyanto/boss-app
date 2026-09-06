@php
    /** @var \App\Models\Invoice $invoice */
    $rp = fn ($n) => number_format((float) $n, 0, ',', '.');
    $w = $thermalWidth === '58' ? '58mm' : '80mm';
    $pad = $thermalWidth === '58' ? '2mm' : '3mm';
    $taxRate = (float) $invoice->subtotal > 0
        ? round((float) $invoice->tax_total / (float) $invoice->subtotal * 100, 2)
        : 0;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_number }} (thermal)</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #d1d5db; }
        .toolbar { text-align: center; padding: 10px; }
        .toolbar button { font: inherit; padding: 8px 16px; cursor: pointer; border: 0; background: #2563eb; color: #fff; border-radius: 6px; }
        .toolbar a { display:block; margin-top:6px; font-size:12px; color:#374151; }
        .receipt {
            width: {{ $w }};
            margin: 8px auto;
            padding: {{ $pad }};
            background: #fff;
            font-family: "Courier New", ui-monospace, monospace;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
        }
        .receipt .c { text-align: center; }
        .receipt .b { font-weight: 700; }
        .receipt hr { border: 0; border-top: 1px dashed #000; margin: 4px 0; }
        .receipt .line { display: flex; justify-content: space-between; gap: 6px; }
        .receipt .line span:last-child { text-align: right; white-space: nowrap; }
        .receipt .item { margin-top: 2px; }
        .receipt .item .desc { word-break: break-word; }
        .receipt .big { font-size: 13px; }
        .receipt .stamp { text-align:center; border:2px solid #000; font-weight:800; letter-spacing:2px; padding:2px 0; margin-top:6px; }
        @media print {
            html, body { background: #fff; }
            .toolbar { display: none; }
            .receipt { margin: 0; width: auto; padding: 0; }
            @page { size: {{ $w }} auto; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Cetak Struk</button>
        <a href="?format=thermal&width={{ $thermalWidth === '58' ? '80' : '58' }}">Ganti lebar ke {{ $thermalWidth === '58' ? '80mm' : '58mm' }}</a>
    </div>

    <div class="receipt">
        <div class="c b big">{{ $company['name'] }}</div>
        @if ($company['address'])<div class="c">{{ $company['address'] }}</div>@endif
        @if ($company['phone'])<div class="c">Telp: {{ $company['phone'] }}</div>@endif
        <hr>
        <div class="line"><span>No.</span><span class="b">{{ $invoice->invoice_number }}</span></div>
        <div class="line"><span>Tgl</span><span>{{ $invoice->generated_at?->translatedFormat('d/m/y H:i') }}</span></div>
        <div class="line"><span>Periode</span><span>{{ $invoice->period_start?->translatedFormat('d/m/y') }}-{{ $invoice->period_end?->translatedFormat('d/m/y') }}</span></div>
        <div class="line"><span>Pelanggan</span><span>{{ \Illuminate\Support\Str::limit($invoice->customer?->name ?? '-', 18) }}</span></div>
        <hr>
        @forelse ($invoice->lineItems as $item)
            <div class="item">
                <div class="desc">{{ $item->description }}</div>
                <div class="line">
                    <span>{{ (int) $item->quantity }} x {{ $rp($item->unit_price) }}</span>
                    <span>{{ $rp($item->line_total) }}</span>
                </div>
            </div>
        @empty
            <div>-</div>
        @endforelse
        <hr>
        <div class="line"><span>Subtotal (DPP)</span><span>{{ $rp($invoice->subtotal) }}</span></div>
        <div class="line"><span>PPN {{ rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.') }}%</span><span>{{ $rp($invoice->tax_total) }}</span></div>
        <div class="line b big"><span>TOTAL</span><span>Rp {{ $rp($invoice->grand_total) }}</span></div>
        <hr>
        <div class="line"><span>Status</span><span class="b">{{ strtoupper($invoice->status->label()) }}</span></div>
        @if ($invoice->paid_at)
            <div class="line"><span>Dibayar</span><span>{{ $invoice->paid_at?->translatedFormat('d/m/y H:i') }}</span></div>
        @endif
        @if ($invoice->status->value === 'paid')
            <div class="stamp">L U N A S</div>
        @endif
        <hr>
        <div class="c">{{ $company['footer_note'] }}</div>
    </div>

    @if ($autoPrint)
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
    @endif
</body>
</html>
