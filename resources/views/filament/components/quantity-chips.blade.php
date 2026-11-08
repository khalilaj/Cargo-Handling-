{{-- Quick amounts, worked out from the real limits. Tapping one fills the field above it. Used for every weight and quantity field. --}}
@if (count($chips) > 0)
    <div class="bs-chips" role="group" aria-label="Quick amounts">
        @foreach ($chips as $chip)
            <button type="button" class="bs-chip-btn" wire:click="{{ $action ?? 'setQuantity' }}({{ isset($which) ? "'{$which}', " : '' }}{{ $chip['kg'] }})" @disabled(! $enabled)>
                <span>{{ $chip['label'] }}</span>
                <strong>{{ number_format($chip['kg']) }} kg</strong>
            </button>
        @endforeach
    </div>
@endif
