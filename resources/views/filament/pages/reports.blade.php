<x-filament-panels::page>
    <x-filament::section heading="Period" description="Figures are rebuilt from the ledger for the dates below. Reversals are netted into the figure they cancel.">
        <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;">
            <div style="min-width:180px;">
                <label style="font-size:13px;font-weight:600;">From</label>
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
            </div>
            <div style="min-width:180px;">
                <label style="font-size:13px;font-weight:600;">Until</label>
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="until" /></x-filament::input.wrapper>
            </div>
            <x-filament::button tag="a" :href="$pdfUrl" icon="heroicon-o-document-arrow-down">Download PDF (all three reports)</x-filament::button>
        </div>
    </x-filament::section>

    @include('filament.pages.partials.report-charts')

    @php $cell = 'padding:8px 10px;border-bottom:1px solid rgba(128,128,128,.25);'; $num = $cell . 'text-align:right;font-variant-numeric:tabular-nums;'; $head = 'padding:8px 10px;text-align:left;font-size:12px;opacity:.7;border-bottom:2px solid rgba(128,128,128,.35);'; @endphp

    <x-filament::section heading="Stock position (kg)" description="closing = opening + discharged − transit loss − delivered">
        <x-slot name="afterHeader">
            <x-filament::button wire:click="downloadStock" size="sm" color="gray" icon="heroicon-o-arrow-down-tray">CSV</x-filament::button>
        </x-slot>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead><tr>
                    <th style="{{ $head }}">Product</th>
                    @foreach (['Opening', 'Discharged (gross)', 'Transit loss', 'Delivered', 'Closing', 'Reversals'] as $h)
                        <th style="{{ $head }}text-align:right;">{{ $h }}</th>
                    @endforeach
                    <th style="{{ $head }}">Ledger check</th>
                </tr></thead>
                <tbody>
                @foreach ($stock['rows'] as $r)
                    <tr>
                        <td style="{{ $cell }}font-weight:600;">{{ strtoupper($r['commodity']->value) }}</td>
                        <td style="{{ $num }}">{{ number_format($r['opening_kg']) }}</td>
                        <td style="{{ $num }}">{{ number_format($r['discharged_gross_kg']) }}</td>
                        <td style="{{ $num }}">{{ number_format($r['transit_loss_kg']) }}</td>
                        <td style="{{ $num }}">{{ number_format($r['delivered_kg']) }}</td>
                        <td style="{{ $num }}font-weight:700;">{{ number_format($r['closing_kg']) }}</td>
                        <td style="{{ $num }}">{{ $r['reversals'] }}</td>
                        <td style="{{ $cell }}color:{{ $r['reconciles'] ? 'var(--success-600,#16a34a)' : 'var(--danger-600,#dc2626)' }};">{{ $r['reconciles'] ? 'reconciles' : 'MISMATCH' }}</td>
                    </tr>
                @endforeach
                    <tr style="font-weight:700;">
                        <td style="{{ $cell }}">Total</td>
                        @foreach (['opening_kg', 'discharged_gross_kg', 'transit_loss_kg', 'delivered_kg', 'closing_kg', 'reversals'] as $k)
                            <td style="{{ $num }}">{{ number_format($stock['totals'][$k]) }}</td>
                        @endforeach
                        <td style="{{ $cell }}"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Vessel reconciliation (kg)" description="Bill of lading against what the shore received.">
        <x-slot name="afterHeader">
            <x-filament::button wire:click="downloadVessels" size="sm" color="gray" icon="heroicon-o-arrow-down-tray">CSV</x-filament::button>
        </x-slot>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead><tr>
                    @foreach (['Voyage', 'Vessel', 'Product', 'Status'] as $h)<th style="{{ $head }}">{{ $h }}</th>@endforeach
                    @foreach (['Bill of lading', 'Received (gross)', 'Transit loss', 'Loss %', 'Outstanding'] as $h)<th style="{{ $head }}text-align:right;">{{ $h }}</th>@endforeach
                </tr></thead>
                <tbody>
                @forelse ($vessels as $v)
                    <tr>
                        <td style="{{ $cell }}font-weight:600;">{{ $v['voyage'] }}</td><td style="{{ $cell }}">{{ $v['vessel'] }}</td>
                        <td style="{{ $cell }}">{{ $v['commodity'] }}</td><td style="{{ $cell }}">{{ $v['status'] }}</td>
                        <td style="{{ $num }}">{{ number_format($v['bl_kg']) }}</td><td style="{{ $num }}">{{ number_format($v['received_gross_kg']) }}</td>
                        <td style="{{ $num }}">{{ number_format($v['transit_loss_kg']) }}</td><td style="{{ $num }}">{{ $v['loss_percent'] }}</td>
                        <td style="{{ $num }}">{{ number_format($v['outstanding_kg']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="{{ $cell }}opacity:.7;">No vessels in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Delivery fulfilment (kg)" description="Ordered against delivered.">
        <x-slot name="afterHeader">
            <x-filament::button wire:click="downloadOrders" size="sm" color="gray" icon="heroicon-o-arrow-down-tray">CSV</x-filament::button>
        </x-slot>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead><tr>
                    @foreach (['Order', 'Customer', 'Product', 'Status'] as $h)<th style="{{ $head }}">{{ $h }}</th>@endforeach
                    @foreach (['Ordered', 'Delivered', 'Outstanding', 'Fulfilled %'] as $h)<th style="{{ $head }}text-align:right;">{{ $h }}</th>@endforeach
                </tr></thead>
                <tbody>
                @forelse ($orders as $o)
                    <tr>
                        <td style="{{ $cell }}font-weight:600;">{{ $o['order'] }}</td><td style="{{ $cell }}">{{ $o['customer'] }}</td>
                        <td style="{{ $cell }}">{{ $o['commodity'] }}</td><td style="{{ $cell }}">{{ $o['status'] }}</td>
                        <td style="{{ $num }}">{{ number_format($o['ordered_kg']) }}</td><td style="{{ $num }}">{{ number_format($o['delivered_kg']) }}</td>
                        <td style="{{ $num }}">{{ number_format($o['outstanding_kg']) }}</td><td style="{{ $num }}">{{ $o['fulfilled_percent'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" style="{{ $cell }}opacity:.7;">No delivery orders in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
