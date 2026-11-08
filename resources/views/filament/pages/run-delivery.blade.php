<x-filament-panels::page>
    @include('filament.components.stepper-flow', ['flow' => $flow, 'showHint' => false])

    <dl class="bs-summary">
        <div><dt>Customer</dt><dd>{{ $this->record->customer }}</dd></div>
        <div><dt>Ordered</dt><dd>{{ number_format($ordered) }} kg</dd></div>
        <div><dt>Loaded</dt><dd>{{ number_format($delivered) }} kg</dd></div>
        <div><dt>Still to load</dt><dd>{{ number_format($remaining) }} kg</dd></div>
        <div><dt>Trucks on site</dt><dd>{{ $onSite->count() }}</dd></div>
    </dl>
    <div class="bs-meter" role="img" aria-label="{{ $percent }} percent of the order delivered"><i style="width: {{ $percent }}%"></i></div>

    @if (! $canOperate)
        <p class="bs-readonly">You can look at this order but not change it.</p>
    @endif

    <section class="bs-panel" wire:key="panel-{{ $panel }}">
        @switch ($panel)
            @case ('open')
                <h2>Open loading</h2>
                <p class="bs-panel-lead">{{ number_format($ordered) }} kg of {{ strtoupper($this->record->commodity->value) }} for {{ $this->record->customer }}. Press the button when trucks can start loading against this order.</p>
                @if ($onSite->isNotEmpty())
                    <p class="bs-panel-lead">Already at the gate for this order: {{ $onSite->map(fn ($v) => $v->truck->plate)->join(', ') }}.</p>
                @endif
                @if ($canOperate)
                    <div class="bs-panel-actions">
                        <x-filament::button size="lg" wire:click="openLoading" wire:loading.attr="disabled" wire:target="openLoading">Start loading</x-filament::button>
                    </div>
                @endif
                @break

            @case ('trucks')
                <h2>Trucks</h2>
                <p class="bs-panel-lead">Take each truck through the gate, the weighbridge and the loading bay. When one has left, gate in the next, or finish the order.</p>

                @if ($onSite->isNotEmpty())
                    <ul class="bs-lane" aria-label="Trucks on site">
                        @foreach ($onSite as $v)
                            <li>
                                <button type="button" wire:click="selectVisit({{ $v->id }})" class="bs-lane-btn {{ $current?->id === $v->id ? 'is-current' : '' }}" @if ($current?->id === $v->id) aria-current="true" @endif>
                                    <strong>{{ $v->truck->plate }}</strong>
                                    <small>{{ $v->status->label() }}</small>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($visitFlow)
                    <h3 class="bs-sub">{{ $current->truck->plate }}{{ $current->driver ? ' · '.$current->driver->name : '' }}</h3>
                    @include('filament.components.stepper-flow', ['flow' => $visitFlow, 'showHint' => false])
                @endif

                @switch ($lane)
                    @case ('gate-in')
                        <h3 class="bs-sub">Gate in a truck</h3>
                        @if ($canOperate)
                            <form wire:submit="gateIn">
                                {{ $this->gateInForm }}
                                <div class="bs-panel-actions">
                                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="gateIn">Gate in truck</x-filament::button>
                                    @if ($onSite->isNotEmpty())
                                        <x-filament::button color="gray" type="button" wire:click="backToTrucks">Back to the trucks on site</x-filament::button>
                                    @endif
                                </div>
                            </form>
                        @endif
                        @break

                    @case ('weigh-in')
                        <h3 class="bs-sub">Weigh the empty truck</h3>
                        @if ($canOperate)
                            <form wire:submit="weighIn">
                                {{ $this->weighInForm }}
                                <div class="bs-panel-actions">
                                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="weighIn">Weigh in</x-filament::button>
                                </div>
                            </form>
                        @endif
                        @break

                    @case ('start')
                        <h3 class="bs-sub">Move the truck into the loading bay</h3>
                        <p class="bs-panel-lead">{{ $current->truck->plate }} is weighed in at {{ number_format($current->weigh_in_kg) }} kg.</p>
                        @if ($canOperate)
                            <div class="bs-panel-actions">
                                <x-filament::button size="lg" wire:click="startTruckLoading" wire:loading.attr="disabled" wire:target="startTruckLoading">Start loading</x-filament::button>
                            </div>
                        @endif
                        @break

                    @case ('load')
                        <h3 class="bs-sub">Load from a tank</h3>
                        @if ($canOperate)
                            <form wire:submit="load">
                                {{ $this->loadForm }}
                                <div class="bs-panel-actions">
                                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="load">Load from tank</x-filament::button>
                                </div>
                            </form>
                        @endif
                        @if ($loads->isNotEmpty())
                            <div class="bs-list">
                                <h3>Loaded onto this truck</h3>
                                <div class="bs-table-wrap">
                                    <table class="bs-table">
                                        <thead><tr><th scope="col">When</th><th scope="col">Tank</th><th scope="col" class="num">Quantity (kg)</th></tr></thead>
                                        <tbody>
                                            @foreach ($loads as $m)
                                                <tr>
                                                    <td>{{ $m->occurred_at->copy()->timezone('Africa/Nairobi')->format('d M H:i') }}</td>
                                                    <td>{{ $m->tank?->code ?? '?' }}</td>
                                                    <td class="num">{{ number_format($m->gross_kg) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                        <h3 class="bs-sub">When the truck is full, weigh it out</h3>
                        @if ($canOperate)
                            <form wire:submit="weighOut">
                                {{ $this->weighOutForm }}
                                <div class="bs-panel-actions">
                                    <x-filament::button type="submit" color="gray" wire:loading.attr="disabled" wire:target="weighOut">Weigh out</x-filament::button>
                                </div>
                            </form>
                        @endif
                        @break

                    @case ('gate-out')
                        <h3 class="bs-sub">Release the truck</h3>
                        <p class="bs-panel-lead">{{ $current->truck->plate }} has been weighed out. The last step of this visit is to let it leave.</p>
                        @if ($canOperate)
                            <div class="bs-panel-actions">
                                <x-filament::button size="lg" wire:click="gateOut" wire:loading.attr="disabled" wire:target="gateOut">Gate out</x-filament::button>
                            </div>
                        @endif
                        @break
                @endswitch

                @if ($canOperate)
                    <div class="bs-panel-actions bs-panel-foot">
                        @if ($lane !== 'gate-in')
                            <x-filament::button color="gray" type="button" wire:click="startGateIn">Gate in another truck</x-filament::button>
                        @endif
                        <x-filament::button color="gray" type="button" wire:click="finishOrder">Finish the order</x-filament::button>
                    </div>
                @endif
                @break

            @case ('complete')
                <h2>Complete the order</h2>
                @if ($onSite->isNotEmpty())
                    <p class="bs-panel-lead">Trucks are still on site: {{ $onSite->map(fn ($v) => $v->truck->plate.' ('.$v->status->label().')')->join(', ') }}. Gate them out or cancel their visits first.</p>
                @elseif ($delivered <= 0)
                    <p class="bs-panel-lead">Nothing has been loaded yet, so the order cannot be completed.</p>
                @else
                    <p class="bs-panel-lead">{{ number_format($delivered) }} kg of {{ number_format($ordered) }} kg was loaded. Press Complete to close the order.</p>
                @endif
                @if ($canOperate)
                    <div class="bs-panel-actions">
                        @if ($onSite->isEmpty() && $delivered > 0)
                            <x-filament::button wire:click="completeOrder" wire:loading.attr="disabled" wire:target="completeOrder">Complete order</x-filament::button>
                        @endif
                        <x-filament::button color="gray" type="button" wire:click="backToTrucks">Back to the trucks</x-filament::button>
                    </div>
                @endif
                @break

            @case ('done')
                <h2>Order complete</h2>
                <p class="bs-panel-lead">{{ number_format($delivered) }} kg was delivered to {{ $this->record->customer }}.</p>
                <div class="bs-panel-actions">
                    <x-filament::button tag="a" :href="$detailsUrl" color="gray">See the order details</x-filament::button>
                    <x-filament::button tag="a" :href="$listUrl" color="gray">Back to delivery orders</x-filament::button>
                </div>
                @break

            @case ('cancelled')
                <h2>Order cancelled</h2>
                <p class="bs-panel-lead">Nothing more can be done on this order.</p>
                <div class="bs-panel-actions">
                    <x-filament::button tag="a" :href="$listUrl" color="gray">Back to delivery orders</x-filament::button>
                </div>
                @break
        @endswitch
    </section>
</x-filament-panels::page>
