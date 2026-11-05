<div wire:poll.30s>
    @if ($visible)
        <x-filament::dropdown placement="bottom-end" width="md" teleport>
            <x-slot name="trigger">
                <x-filament::icon-button
                    icon="heroicon-o-bell"
                    :badge="$alerts->count() ?: null"
                    :badge-color="$color"
                    label="Needs attention"
                    size="lg"
                />
            </x-slot>

            <div style="max-height:70vh;overflow-y:auto;padding:6px;">
                <div style="padding:8px 10px 6px;font-weight:700;font-size:14px;">Needs attention</div>

                @forelse ($alerts as $a)
                    @php
                        $c = ['danger' => 'var(--danger-500,#ef4444)', 'warning' => 'var(--warning-500,#f59e0b)', 'info' => 'var(--info-500,#3b82f6)'][$a['level']];
                    @endphp
                    <a href="{{ $a['url'] ?? '#' }}"
                       style="display:block;text-decoration:none;color:inherit;border-left:4px solid {{ $c }};padding:7px 12px;margin:6px;border-radius:0 8px 8px 0;background:rgba(128,128,128,.08);">
                        <div style="font-weight:600;font-size:13.5px;">{{ $a['title'] }}</div>
                        <div style="font-size:12.5px;opacity:.75;">{{ $a['detail'] }}</div>
                    </a>
                @empty
                    <div style="padding:14px 12px 18px;font-size:13.5px;opacity:.7;">All clear. Nothing needs your attention right now.</div>
                @endforelse
            </div>
        </x-filament::dropdown>
    @endif
</div>
