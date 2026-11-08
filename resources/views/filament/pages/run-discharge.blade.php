@php($readOnly = ! $canOperate)
<x-filament-panels::page>
    @include('filament.components.stepper-flow', ['flow' => $flow, 'showHint' => false])

    <div x-data="{ more: false }" class="bs-summary-wrap" :class="{ 'is-open': more }">
    <dl class="bs-summary">
        <div class="bs-secondary"><dt>Bill of lading</dt><dd>{{ number_format($this->record->bl_quantity_kg) }} kg</dd></div>
        <div class="bs-secondary"><dt>Received</dt><dd>{{ number_format($gross) }} kg</dd></div>
        <div class="bs-secondary"><dt>Transit loss</dt><dd>{{ number_format($loss) }} kg</dd></div>
        <div><dt>In the tanks</dt><dd>{{ number_format($net) }} kg</dd></div>
        <div><dt>Still on the ship</dt><dd>{{ number_format($remaining) }} kg</dd></div>
    </dl>
    <button type="button" class="bs-more-btn" x-on:click="more = ! more"><span x-text="more ? 'Hide figures' : 'More figures'">More figures</span></button>
    </div>
    <div class="bs-meter" role="img" aria-label="{{ $percent }} percent of the bill of lading received"><i style="width: {{ $percent }}%"></i></div>

    {{-- Every role sees the same steps and the same forms. Roles that may not press a step see its controls switched off. --}}
    @if ($readOnly)
        <p class="bs-readonly">You can look at this voyage but not change it. The controls below are switched off for your role; operators and supervisors press them.</p>
    @endif

    <section class="bs-panel" wire:key="panel-{{ $panel }}">
        @switch ($panel)
            @case ('berth')
                <h2>Berth the ship</h2>
                <p class="bs-panel-lead">When the ship arrives, choose a berth that is free. Then it can start discharging.</p>
                <form wire:submit="berth">
                    {{ $this->berthForm }}
                    <div class="bs-panel-actions">
                        <x-filament::button type="submit" :disabled="$readOnly" wire:loading.attr="disabled" wire:target="berth">Mark berthed and continue</x-filament::button>
                    </div>
                </form>
                @break

            @case ('start')
                <h2>Start the discharge</h2>
                <p class="bs-panel-lead">{{ $this->record->vessel_name }} is alongside at {{ $this->record->berth?->code ?? 'its berth' }}. Press the button when pumping begins.</p>
                <div class="bs-panel-actions">
                    <x-filament::button size="lg" :disabled="$readOnly" wire:click="start" wire:loading.attr="disabled" wire:target="start">Start discharge</x-filament::button>
                </div>
                @break

            @case ('pump')
                <h2>Pump into a tank</h2>
                <p class="bs-panel-lead">Choose the tank and the quantity. The 0.5% transit loss is taken off for you. Repeat for each tank you fill.</p>
                <form wire:submit="pump">
                    {{ $this->pumpForm }}
                    <div class="bs-panel-actions">
                        <x-filament::button type="submit" :disabled="$readOnly" wire:loading.attr="disabled" wire:target="pump">Pump into tank</x-filament::button>
                        @if ($fullyIn)
                            <x-filament::button color="gray" wire:click="finishPumping" type="button">Finish pumping</x-filament::button>
                        @endif
                    </div>
                </form>
                @include('filament.components.pump-list', ['pumps' => $pumps])
                @break

            @case ('complete')
                <h2>Complete the voyage</h2>
                @if ($fullyIn)
                    <p class="bs-panel-lead">{{ number_format($net) }} kg received in the tanks, after {{ number_format($loss) }} kg lost in transit ({{ number_format($gross) }} kg was pumped from the ship). Press Complete to close the voyage.</p>
                @else
                    <p class="bs-panel-lead">Less than 99% of the bill of lading is in, so only a supervisor can close it, with a written reason that is kept on the voyage. Otherwise go back and pump the rest.</p>
                @endif
                <form wire:submit="complete">
                    @if (! $fullyIn)
                        {{ $this->completeForm }}
                    @endif
                    <div class="bs-panel-actions">
                        <x-filament::button type="submit" :disabled="$readOnly || (! $fullyIn && ! $canCloseEarly)" wire:loading.attr="disabled" wire:target="complete">Complete voyage</x-filament::button>
                        <x-filament::button color="gray" wire:click="backToPumping" type="button">Back to pumping</x-filament::button>
                    </div>
                </form>
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
