<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Terminal report</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
    h1 { font-size: 18px; margin: 6px 0 2px; color: #153037; }
    .brand { border-bottom: 3px solid #153037; padding-bottom: 8px; margin-bottom: 8px; }
    h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 2px solid #153037; padding-bottom: 3px; }
    .meta { color: #6b7280; margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f0efea; text-align: left; padding: 5px 6px; border-bottom: 1px solid #dcdad2; }
    td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
    .n { text-align: right; }
    .total td { font-weight: bold; border-top: 2px solid #153037; }
    .ok { color: #047857; } .bad { color: #b91c1c; }
    .note { color: #6b7280; font-size: 9px; margin-top: 4px; }
</style>
</head>
<body>
<div class="brand"><img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/bulkstream-logo.png'))) }}" style="height:26px"></div>
<h1>Cargo Handling: terminal report</h1>
<div class="meta">Period {{ $from->format('d M Y') }} to {{ $until->format('d M Y') }} · generated {{ \App\Support\KenyaTime::of(now())->format('d M Y H:i') }} by {{ $generatedBy }}</div>

<h2>Stock position (kg)</h2>
<table>
    <tr><th>Product</th><th class="n">Opening</th><th class="n">Discharged (gross)</th><th class="n">Transit loss</th><th class="n">Delivered</th><th class="n">Closing</th><th class="n">Reversals</th><th>Ledger check</th></tr>
    @foreach ($stock['rows'] as $r)
        <tr>
            <td>{{ strtoupper($r['commodity']->value) }}</td>
            <td class="n">{{ number_format($r['opening_kg']) }}</td>
            <td class="n">{{ number_format($r['discharged_gross_kg']) }}</td>
            <td class="n">{{ number_format($r['transit_loss_kg']) }}</td>
            <td class="n">{{ number_format($r['delivered_kg']) }}</td>
            <td class="n"><strong>{{ number_format($r['closing_kg']) }}</strong></td>
            <td class="n">{{ $r['reversals'] }}</td>
            <td class="{{ $r['reconciles'] ? 'ok' : 'bad' }}">{{ $r['reconciles'] ? 'reconciles' : 'MISMATCH' }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td>Total</td>
        <td class="n">{{ number_format($stock['totals']['opening_kg']) }}</td>
        <td class="n">{{ number_format($stock['totals']['discharged_gross_kg']) }}</td>
        <td class="n">{{ number_format($stock['totals']['transit_loss_kg']) }}</td>
        <td class="n">{{ number_format($stock['totals']['delivered_kg']) }}</td>
        <td class="n">{{ number_format($stock['totals']['closing_kg']) }}</td>
        <td class="n">{{ $stock['totals']['reversals'] }}</td><td></td>
    </tr>
</table>
<div class="note">Closing = opening + discharged - transit loss - delivered. Reversed movements are netted into the figure they cancel.</div>

<h2>Vessel reconciliation (kg)</h2>
<table>
    <tr><th>Voyage</th><th>Vessel</th><th>Product</th><th>Status</th><th class="n">Bill of lading</th><th class="n">Received (gross)</th><th class="n">Transit loss</th><th class="n">Loss %</th><th class="n">Outstanding</th></tr>
    @forelse ($vessels as $v)
        <tr><td>{{ $v['voyage'] }}</td><td>{{ $v['vessel'] }}</td><td>{{ $v['commodity'] }}</td><td>{{ $v['status'] }}</td>
            <td class="n">{{ number_format($v['bl_kg']) }}</td><td class="n">{{ number_format($v['received_gross_kg']) }}</td>
            <td class="n">{{ number_format($v['transit_loss_kg']) }}</td><td class="n">{{ $v['loss_percent'] }}</td><td class="n">{{ number_format($v['outstanding_kg']) }}</td></tr>
    @empty
        <tr><td colspan="9">No vessels in this period.</td></tr>
    @endforelse
</table>

<h2>Delivery fulfilment (kg)</h2>
<table>
    <tr><th>Order</th><th>Customer</th><th>Product</th><th>Status</th><th class="n">Ordered</th><th class="n">Delivered</th><th class="n">Outstanding</th><th class="n">Fulfilled %</th></tr>
    @forelse ($orders as $o)
        <tr><td>{{ $o['order'] }}</td><td>{{ $o['customer'] }}</td><td>{{ $o['commodity'] }}</td><td>{{ $o['status'] }}</td>
            <td class="n">{{ number_format($o['ordered_kg']) }}</td><td class="n">{{ number_format($o['delivered_kg']) }}</td>
            <td class="n">{{ number_format($o['outstanding_kg']) }}</td><td class="n">{{ $o['fulfilled_percent'] }}</td></tr>
    @empty
        <tr><td colspan="8">No delivery orders in this period.</td></tr>
    @endforelse
</table>
</body>
</html>
