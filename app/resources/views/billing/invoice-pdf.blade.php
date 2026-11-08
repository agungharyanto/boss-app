{{-- v0.13.5.2 — View PDF server-side (dompdf). Tata letak TABEL (bukan
     flexbox — dukungan flexbox dompdf lemah). Angka via partial bersama
     billing.partials.invoice-items-totals (sama dengan browser-print). Kop
     dari CompanyProfileService::resolveFor (fallback env terakhir di service).
     Semua data user di-escape Blade {{ }}. --}}
@php /** @var \App\Models\Invoice $invoice */ @endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #1a1a1a; }
        .sheet { padding: 4mm; }
        h1 { font-size: 22px; letter-spacing: 2px; margin: 0; color: #111; }
        .muted { color: #6b7280; }
        .company-name { font-size: 15px; font-weight: 700; }
        .head { width: 100%; margin-bottom: 24px; }
        .head td { vertical-align: top; }
        .head td.right { text-align: right; }
        .parties { width: 100%; margin-bottom: 8px; }
        .parties td { vertical-align: top; width: 50%; }
        .meta td { padding: 2px 0; }
        .meta td:first-child { color: #6b7280; padding-right: 16px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table.items th, table.items td { padding: 8px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        table.items th { background: #f9fafb; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; }
        table.items td.num, table.items th.num { text-align: right; }
        .totals { margin-top: 16px; margin-left: auto; width: 260px; border-collapse: collapse; }
        .totals td { padding: 5px 0; }
        .totals td:last-child { text-align: right; }
        .totals tr.grand td { border-top: 2px solid #111; font-weight: 700; font-size: 14px; padding-top: 8px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
        .badge.paid { background: #dcfce7; color: #166534; }
        .badge.other { background: #fef9c3; color: #854d0e; }
        .footer { margin-top: 48px; color: #6b7280; font-size: 11px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .paid-stamp { display: inline-block; margin-top: 8px; border: 3px solid #16a34a; color: #16a34a; font-weight: 800; font-size: 20px; letter-spacing: 3px; padding: 4px 18px; border-radius: 6px; }
        .logo { max-height: 56px; margin-bottom: 6px; }
        @page { margin: 14mm; }
    </style>
</head>
<body>
    <div class="sheet">
        <table class="head">
            <tr>
                <td>
                    @if ($logoDataUri)
                        <img class="logo" src="{{ $logoDataUri }}" alt="">
                    @endif
                    <div class="company-name">{{ $company['brand_name'] ?: $company['legal_name'] }}</div>
                    @if ($company['legal_name'] && $company['legal_name'] !== $company['brand_name'])
                        <div class="muted">{{ $company['legal_name'] }}</div>
                    @endif
                    @if ($company['address'])<div class="muted">{{ $company['address'] }}</div>@endif
                    @if ($company['email'])<div class="muted">{{ $company['email'] }}</div>@endif
                    @if (($company['cs'] ?? '') !== '')<div class="muted">CS: {{ $company['cs'] }}</div>@endif
                </td>
                <td class="right">
                    <h1>INVOICE</h1>
                    <div class="muted">{{ $invoice->invoice_number }}</div>
                    <div style="margin-top:6px">
                        <span class="badge {{ $invoice->status->value === 'paid' ? 'paid' : 'other' }}">{{ $invoice->status->label() }}</span>
                    </div>
                </td>
            </tr>
        </table>

        <table class="parties">
            <tr>
                <td>
                    <div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">Ditagihkan kepada</div>
                    <div style="font-weight:700;margin-top:2px">{{ $invoice->customer?->name ?? '—' }}</div>
                    @if ($invoice->customer?->address)<div class="muted">{{ $invoice->customer->address }}</div>@endif
                    @if ($invoice->customer?->phone_number)<div class="muted">{{ $invoice->customer->phone_number }}</div>@endif
                </td>
                <td>
                    <table class="meta">
                        <tr><td>Tanggal terbit</td><td>{{ $invoice->generated_at?->translatedFormat('d M Y') }}</td></tr>
                        <tr><td>Jatuh tempo</td><td>{{ $invoice->due_date?->translatedFormat('d M Y') }}</td></tr>
                        <tr><td>Periode</td><td>{{ $invoice->period_start?->translatedFormat('d M Y') }} – {{ $invoice->period_end?->translatedFormat('d M Y') }}</td></tr>
                        @if ($invoice->paid_at)
                            <tr><td>Dibayar</td><td>{{ $invoice->paid_at?->translatedFormat('d M Y H:i') }}</td></tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>

        @include('billing.partials.invoice-items-totals', ['invoice' => $invoice])

        @if ($invoice->status->value === 'paid')
            <div class="paid-stamp">LUNAS</div>
        @endif

        <div class="footer">{{ $company['footer_note'] ?? '' }}</div>
    </div>
</body>
</html>
