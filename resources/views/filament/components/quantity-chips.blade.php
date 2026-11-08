{{-- Quick amounts, worked out from the real limits. Tapping one fills the Quantity field above. --}}
@if (count($chips) > 0)
    <div class="bs-chips" role="group" aria-label="Quick amounts">
        @foreach ($chips as $chip)
            <button type="button" class="bs-chip-btn" wire:click="setQuantity({{ $chip['kg'] }})" @disabled(! $enabled)>
                <span>{{ $chip['label'] }}</span>
                <strong>{{ number_format($chip['kg']) }} kg</strong>
            </button>
        @endforeach
    </div>
@endif
