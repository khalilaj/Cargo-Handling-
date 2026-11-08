<x-filament::section heading="Report visuals" description="Charts for the period selected above." data-report-charts>
    @if (! $charts['hasData'])
        <p class="bs-rc-empty">No movements in this period. Pick a wider date range to see charts.</p>
    @else
        <div class="bs-rc-grid">
            <section class="bs-rc-card" aria-labelledby="rc-stock">
                <h3 id="rc-stock">Stock against tank capacity</h3>
                @foreach ($charts['stock'] as $b)
                    <div class="bs-rc-row">
                        <div class="bs-rc-label"><span>{{ $b['label'] }}</span><span>{{ number_format($b['closing_kg'] / 1000, 1) }} t of {{ number_format($b['capacity_kg'] / 1000, 1) }} t ({{ $b['pct'] }}%)</span></div>
                        <div class="bs-rc-track" role="img" aria-label="{{ $b['label'] }} stock {{ $b['pct'] }} percent of capacity"><span class="bs-rc-fill" style="width:{{ $b['pct'] }}%"></span></div>
                    </div>
                @endforeach
            </section>

            <section class="bs-rc-card" aria-labelledby="rc-vessels">
                <h3 id="rc-vessels">Received and transit loss per voyage</h3>
                @forelse ($charts['vessels'] as $b)
                    <div class="bs-rc-row">
                        <div class="bs-rc-label"><span>{{ $b['label'] }}</span><span>{{ number_format($b['net_kg'] / 1000, 1) }} t received, {{ number_format($b['loss_kg']) }} kg lost</span></div>
                        <div class="bs-rc-track" role="img" aria-label="{{ $b['label'] }} received, loss and outstanding">
                            <span class="bs-rc-fill" style="width:{{ $b['net_pct'] }}%"></span><span class="bs-rc-loss" style="width:{{ $b['loss_pct'] }}%"></span>
                        </div>
                    </div>
                @empty
                    <p class="bs-rc-empty">No vessels in this period.</p>
                @endforelse
                <p class="bs-rc-key"><i class="bs-rc-fill"></i> received (net) <i class="bs-rc-loss"></i> transit loss <i class="bs-rc-track"></i> still to come</p>
            </section>

            <section class="bs-rc-card" aria-labelledby="rc-orders">
                <h3 id="rc-orders">Ordered against delivered</h3>
                @forelse ($charts['orders'] as $b)
                    <div class="bs-rc-row">
                        <div class="bs-rc-label"><span>{{ $b['label'] }}</span><span>{{ number_format($b['delivered_kg'] / 1000, 1) }} of {{ number_format($b['ordered_kg'] / 1000, 1) }} t ({{ $b['pct'] }}%)</span></div>
                        <div class="bs-rc-track" role="img" aria-label="{{ $b['label'] }} delivered {{ $b['pct'] }} percent"><span class="bs-rc-fill" style="width:{{ $b['pct'] }}%"></span></div>
                    </div>
                @empty
                    <p class="bs-rc-empty">No delivery orders in this period.</p>
                @endforelse
            </section>
        </div>
    @endif
</x-filament::section>
