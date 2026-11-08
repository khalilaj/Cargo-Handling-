<x-filament-panels::page>
    @include('filament.components.stepper-flow', ['flow' => $flow, 'showHint' => false])

    <dl class="bs-summary">
        <div><dt>Bill of lading</dt><dd>{{ number_format($this->record->bl_quantity_kg) }} kg</dd></div>
        <div><dt>Received</dt><dd>{{ number_format($gross) }} kg</dd></div>
        <div><dt>Transit loss</dt><dd>{{ number_format($loss) }} kg</dd></div>
        <div><dt>In the tanks</dt><dd>{{ number_format($net) }} kg</dd></div>
        <div><dt>Still on the ship</dt><dd>{{ number_format($remaining) }} kg</dd></div>
    </dl>
    <div class="bs-meter" role="img" aria-label="{{ $percent }} percent of the bill of lading received"><i style="width: {{ $percent }}%"></i></div>

    @if (! $canOperate)
        <p class="bs-readonly">You can look at this voyage but not change it.</p>
    @endif

    <section class="bs-panel" wire:key="panel-{{ $panel }}">
        @switch ($panel)
            @case ('berth')
                <h2>Berth the ship</h2>
                <p class="bs-panel-lead">When the ship arrives, choose a berth that is free. Then it can start discharging.</p>
                <form wire:submit="berth">
                    {{ $this->berthForm }}
                    @if ($canOperate)
                        <div class="bs-panel-actions">
                            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="berth">Mark berthed and continue</x-filament::button>
                        </div>
                    @endif
                </form>
                @break

            @case ('start')
                <h2>Start the discharge</h2>
                <p class="bs-panel-lead">{{ $this->record->vessel_name }} is alongside at {{ $this->record->berth?->code ?? 'its berth' }}. Press the button when pumping begins.</p>
                @if ($canOperate)
                    <div class="bs-panel-actions">
                        <x-filament::button size="lg" wire:click="start" wire:loading.attr="disabled" wire:target="start">Start discharge</x-filament::button>
                    </div>
                @endif
                @break

            @case ('pump')
                <h2>Pump into a tank</h2>
                <p class="bs-panel-lead">Choose the tank and the quantity. The 0.5% transit loss is taken off for you. Repeat for each tank you fill.</p>
                @if ($canOperate)
                    <form wire:submit="pump">
                        {{ $this->pumpForm }}
                        <div class="bs-panel-actions">
                            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="pump">Pump into tank</x-filament::button>
                            <x-filament::button color="gray" wire:click="finishPumping" type="button">
                                {{ $fullyIn ? 'Finish pumping' : 'Finish pumping early' }}
                            </x-filament::button>
                        </div>
                    </form>
                @endif
                @include('filament.components.pump-list', ['pumps' => $pumps])
                @break

            @case ('complete')
                <h2>Complete the voyage</h2>
                @if ($fullyIn)
                    <p class="bs-panel-lead">{{ number_format($gross) }} kg received, {{ number_format($loss) }} kg lost in transit, {{ number_format($net) }} kg in the tanks. Press Complete to close the voyage.</p>
                @elseif ($canCloseEarly)
                    <p class="bs-panel-lead">Less than 99% of the bill of lading is in. A supervisor can close it with a written reason, which is kept on the voyage.</p>
                @else
                    <p class="bs-panel-lead">Less than 99% of the bill of lading is in, so only a supervisor can close it. Go back and pump the rest, or ask a supervisor.</p>
                @endif
                @if ($canOperate)
                    <form wire:submit="complete">
                        @if (! $fullyIn && $canCloseEarly)
                            {{ $this->completeForm }}
                        @endif
                        <div class="bs-panel-actions">
                            @if ($fullyIn || $canCloseEarly)
                                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="complete">Complete voyage</x-filament::button>
                            @endif
                            <x-filament::button color="gray" wire:click="backToPumping" type="button">Back to pumping</x-filament::button>
                        </div>
                    </form>
                @endif
                @break

            @case ('done')
                <h2>Voyage complete</h2>
                <p class="bs-panel-lead">{{ number_format($net) }} kg is in the tanks after {{ number_format($loss) }} kg of transit loss.</p>
                <div class="bs-panel-actions">
                    <x-filament::button tag="a" :href="$detailsUrl" color="gray">See the voyage details</x-filament::button>
                    <x-filament::button tag="a" :href="$listUrl" color="gray">Back to vessel discharges</x-filament::button>
                </div>
                @include('filament.components.pump-list', ['pumps' => $pumps])
                @break

            @case ('cancelled')
                <h2>Voyage cancelled</h2>
                <p class="bs-panel-lead">Nothing more can be done on this voyage.</p>
                <div class="bs-panel-actions">
                    <x-filament::button tag="a" :href="$listUrl" color="gray">Back to vessel discharges</x-filament::button>
                </div>
                @break
        @endswitch
    </section>
</x-filament-panels::page>
