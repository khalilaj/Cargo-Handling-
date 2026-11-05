<x-filament-widgets::widget>
    <x-filament::section heading="Needs attention" description="Tank limits, late vessels and open deliveries.">
        <div wire:poll.15s style="display:flex;flex-direction:column;gap:10px;">
            @forelse ($alerts as $a)
                @php
                    $c = ['danger' => 'var(--danger-500,#ef4444)', 'warning' => 'var(--warning-500,#f59e0b)', 'info' => 'var(--info-500,#3b82f6)'][$a['level']];
                @endphp
                <div style="border-left:4px solid {{ $c }};padding:6px 12px;background:rgba(128,128,128,.07);border-radius:0 8px 8px 0;">
                    <div style="font-weight:600;font-size:14px;">{{ $a['title'] }}</div>
                    <div style="font-size:12.5px;opacity:.75;">{{ $a['detail'] }}</div>
                </div>
            @empty
                <div style="font-size:14px;opacity:.7;">All clear. Nothing needs attention right now.</div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
