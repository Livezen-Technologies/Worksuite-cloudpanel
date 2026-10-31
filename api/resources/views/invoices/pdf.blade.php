<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Invoice {{ $invoice->number }}</title>
<style>
  body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1f2937; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  .muted { color: #6b7280; }
  table { width: 100%; border-collapse: collapse; }
  .head td { vertical-align: top; }
  .lines th { text-align: left; border-bottom: 1px solid #d1d5db; padding: 6px 4px; font-size: 10px; text-transform: uppercase; color: #6b7280; }
  .lines td { padding: 6px 4px; border-bottom: 1px solid #f3f4f6; }
  .num { text-align: right; white-space: nowrap; }
  .totals td { padding: 4px; }
  .total td { font-weight: bold; font-size: 13px; border-top: 1px solid #1f2937; }
  .status { display: inline-block; padding: 2px 8px; border: 1px solid #9ca3af; border-radius: 3px; text-transform: uppercase; font-size: 10px; }
</style>
</head>
<body>
<table class="head">
  <tr>
    <td>
      <h1>Invoice</h1>
      <div>No. <strong>{{ $invoice->number }}</strong></div>
      <div class="muted">Period {{ $invoice->period_start->format('j M Y') }} – {{ $invoice->period_end->format('j M Y') }}</div>
      <div class="muted">Issued {{ ($invoice->created_at ?? now())->format('j M Y') }}@if($invoice->due_at), due {{ $invoice->due_at->format('j M Y') }}@endif</div>
      <div style="margin-top:6px"><span class="status">{{ $invoice->status }}</span></div>
    </td>
    <td style="text-align:right">
      <strong>{{ $seller['name'] }}</strong><br>
      @if($seller['address']){!! nl2br(e($seller['address'])) !!}<br>@endif {{-- nosemgrep: blade-unescaped-output -- escaped by e() first --}}
      @if($seller['vat_id'])VAT ID {{ $seller['vat_id'] }}@endif
    </td>
  </tr>
</table>

<div style="margin:24px 0 16px">
  <div class="muted">Bill to</div>
  <strong>{{ $org?->name }}</strong><br>
  @if($org?->billing_email){{ $org->billing_email }}@endif
</div>

<table class="lines">
  <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
  <tbody>
  @foreach($invoice->line_items ?? [] as $line)
    <tr>
      <td>{{ $line['description'] }}</td>
      <td class="num">{{ rtrim(rtrim(number_format($line['quantity'], 2, '.', ''), '0'), '.') }} {{ $line['unit'] ?? '' }}</td>
      <td class="num">{{ $money($line['unit_cents']) }}</td>
      <td class="num">{{ $money($line['amount_cents']) }}</td>
    </tr>
  @endforeach
  </tbody>
</table>

<table class="totals" style="width:45%; margin-left:55%; margin-top:12px">
  <tr><td>Subtotal</td><td class="num">{{ $money($invoice->subtotal_cents) }}</td></tr>
  <tr><td>{{ $taxLabel }} {{ rtrim(rtrim(number_format($invoice->tax_rate * 100, 2, '.', ''), '0'), '.') }}%</td><td class="num">{{ $money($invoice->tax_cents) }}</td></tr>
  <tr class="total"><td>Total ({{ strtoupper($invoice->currency) }})</td><td class="num">{{ $money($invoice->amount_cents) }}</td></tr>
</table>

<p class="muted" style="margin-top:32px">Payments are processed by our payment provider; card details are never stored by us.</p>
</body>
</html>
