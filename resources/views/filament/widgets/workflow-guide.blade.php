<x-filament-widgets::widget>
    <details class="bs-guide" open>
        <summary>
            <span class="bs-guide-title">How the terminal works</span>
            <span class="bs-guide-sub">Follow the steps in order. Each one opens the right screen.</span>
        </summary>
        <ol class="bs-guide-list">
            @foreach ($steps as $step)
                <li class="bs-guide-step">
                    <span class="bs-guide-num" aria-hidden="true">{{ $loop->iteration }}</span>
                    <div class="bs-guide-body">
                        <h3>{{ $step['title'] }}@if ($step['count'] !== '') <span class="bs-chip">{{ $step['count'] }}</span>@endif</h3>
                        <p>{{ $step['what'] }}</p>
                        @if (count($step['links']))
                            <div class="bs-guide-links">
                                @foreach ($step['links'] as $label => $url)
                                    <a href="{{ $url }}" class="bs-guide-link">{{ $label }}</a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </details>
</x-filament-widgets::widget>
