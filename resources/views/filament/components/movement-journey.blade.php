{{-- The cargo's story: steps top to bottom, then one "where it is now" sentence. $story comes from CargoMovementResource::story(). --}}
<div class="bs-journey">
    <h3 class="bs-journey-title">Where the cargo went</h3>
    <ol class="bs-journey-steps">
        @foreach ($story['steps'] as $step)
            <li class="bs-journey-step bs-journey-step--{{ $step['state'] }}">
                <span class="bs-journey-dot" aria-hidden="true">{{ $step['state'] === 'warn' ? '!' : ($step['state'] === 'now' ? '●' : '✓') }}</span>
                <div class="bs-journey-body">
                    <strong>{{ $step['title'] }}</strong>
                    @if ($step['detail'])
                        <span>{{ $step['detail'] }}</span>
                    @endif
                    @foreach ($step['links'] as [$label, $url])
                        <a href="{{ $url }}" class="bs-journey-link">{{ $label }}</a>
                    @endforeach
                </div>
            </li>
        @endforeach
    </ol>
    @foreach ($story['notes'] as $note)
        <p class="bs-journey-note">{{ $note }}</p>
    @endforeach
    <div class="bs-now">
        <h3 class="bs-journey-title">Where it is now</h3>
        <p>{{ $story['now'] }}</p>
    </div>
</div>
