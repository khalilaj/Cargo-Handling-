{{-- The steps with done / current / to-do state and the next-step sentence. $flow comes from WorkflowSteps::for(). --}}
@php
    $all = $flow['steps'];
    $at = collect($all)->search(fn ($s) => $s['state'] === 'current');
    if ($at === false) {
        $at = collect($all)->contains(fn ($s) => $s['state'] === 'done') ? count($all) - 1 : null;
    }
@endphp
<div class="bs-flow {{ $flow['cancelled'] ? 'bs-flow--cancelled' : '' }}">
    {{-- On a phone the full list of steps is replaced by one line, so the screen stays about the next thing to do. --}}
    @if ($at !== null && ! $flow['cancelled'])
        <p class="bs-step-count">Step {{ $at + 1 }} of {{ count($all) }} · {{ $all[$at]['label'] }}</p>
    @endif
    <ol class="bs-steps" aria-label="Progress">
        @foreach ($flow['steps'] as $step)
            <li class="bs-step bs-step--{{ $step['state'] }}" @if ($step['state'] === 'current') aria-current="step" @endif>
                <span class="bs-dot" aria-hidden="true">{{ $step['state'] === 'done' ? '✓' : $loop->iteration }}</span>
                <span class="bs-step-label">{{ $step['label'] }}</span>
            </li>
        @endforeach
    </ol>
    @if (($showHint ?? true) && $flow['hint'] !== '')
        <p class="bs-next">{{ $flow['hint'] }}</p>
    @endif
</div>
