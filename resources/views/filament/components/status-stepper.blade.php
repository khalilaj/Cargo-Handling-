@php($flow = \App\Filament\Support\WorkflowSteps::for($getRecord()))
<div class="bs-flow {{ $flow['cancelled'] ? 'bs-flow--cancelled' : '' }}">
    <ol class="bs-steps" aria-label="Progress">
        @foreach ($flow['steps'] as $step)
            <li class="bs-step bs-step--{{ $step['state'] }}" @if ($step['state'] === 'current') aria-current="step" @endif>
                <span class="bs-dot" aria-hidden="true">{{ $step['state'] === 'done' ? '✓' : $loop->iteration }}</span>
                <span class="bs-step-label">{{ $step['label'] }}</span>
            </li>
        @endforeach
    </ol>
    <p class="bs-next">{{ $flow['hint'] }}</p>
</div>
