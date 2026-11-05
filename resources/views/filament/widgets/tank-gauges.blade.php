<x-filament-widgets::widget>
    <x-filament::section heading="Tank levels" description="Live view of every storage tank. Refreshes every 10 seconds.">
        <div wire:poll.10s style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;">
            @foreach ($tanks as $tank)
                @php
                    $pct = $tank->fillPercent();
                    $color = $pct >= 90 ? 'var(--danger-500,#ef4444)' : ($pct >= 70 ? 'var(--warning-500,#f59e0b)' : 'var(--success-500,#22c55e)');
                    $h = max(0, min(100, $pct));
                    $fillH = $h; $fillY = 14 + 100 - $fillH;
                    $free = number_format(($tank->capacity_kg - $tank->current_volume_kg) / 1000, 1);
                @endphp
                <a href="{{ \App\Filament\Resources\TankResource::getUrl('view', ['record' => $tank]) }}"
                   style="display:block;text-decoration:none;color:inherit;border:1px solid rgba(128,128,128,.28);border-radius:14px;padding:14px;text-align:center;">
                    <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px;">
                        <strong>{{ $tank->code }}</strong>
                        <span style="opacity:.7;">{{ strtoupper($tank->commodity->value) }}{{ $tank->requires_heating ? ' · heated' : '' }}</span>
                    </div>
                    <svg viewBox="0 0 84 128" width="96" height="122" role="img" aria-label="{{ $tank->code }} is {{ $pct }} percent full" style="margin:8px auto 2px;display:block;">
                        <defs><clipPath id="clip-{{ $tank->id }}"><rect x="6" y="14" width="72" height="100" rx="10"/></clipPath></defs>
                        <rect x="6" y="14" width="72" height="100" rx="10" fill="rgba(128,128,128,.12)" stroke="rgba(128,128,128,.5)" stroke-width="2"/>
                        <rect x="6" y="{{ $fillY }}" width="72" height="{{ $fillH }}" fill="{{ $color }}" clip-path="url(#clip-{{ $tank->id }})" opacity=".85"/>
                        <text x="42" y="70" text-anchor="middle" font-size="17" font-weight="700" fill="currentColor" style="paint-order:stroke;stroke:rgba(255,255,255,.55);stroke-width:3px;">{{ $pct }}%</text>
                    </svg>
                    <div style="font-size:13px;">{{ number_format($tank->current_volume_kg / 1000, 1) }} t <span style="opacity:.6;">of {{ number_format($tank->capacity_kg / 1000, 1) }} t</span></div>
                    <div style="font-size:12px;opacity:.65;">{{ $free }} t free</div>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
