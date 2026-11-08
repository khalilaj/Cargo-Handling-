@php($readOnly = ! $canOperate)
<x-filament-panels::page>
    @include('filament.components.stepper-flow', ['flow' => $flow, 'showHint' => false])

    <div x-data="{ more: false }" class="bs-summary-wrap" :class="{ 'is-open': more }">
    <dl class="bs-summary">
        <div><dt>Customer</dt><dd>{{ $this->record->customer }}</dd></div>
        <div class="bs-secondary"><dt>Ordered</dt><dd>{{ number_format($ordered) }} kg</dd></div>
        <div><dt>Loaded</dt><dd>{{ number_format($delivered) }} kg</dd></div>
        <div><dt>Still to load</dt><dd>{{ number_format($remaining) }} kg</dd></div>
        <div class="bs-secondary"><dt>Trucks on site</dt><dd>{{ $onSite->count() }}</dd></div>
    </dl>
    <button type="button" class="bs-more-btn" x-on:click="more = ! more"><span x-text="more ? 'Hide figures' : 'More figures'">More figures</span></button>
    </div>
    <div class="bs-meter" role="img" aria-label="{{ $percent }} percent of the order delivered"><i style="width: {{ $percent }}%"></i></div>

    {{-- Every role sees the same steps and the same forms. Roles that may not press a step see its controls switched off. --}}
    @if ($readOnly)
        <p class="bs-readonly">You can look at this order but not change it. The controls below are switched off for your role; operators and supervisors press them.</p>
    @endif

    <section class="bs-panel" wire:key="panel-{{ $panel }}">
        @switch ($panel)
            @case ('open')
                <h2>Open loading</h2>
                <p class="bs-panel-lead">{{ number_format($ordered) }} kg of {{ strtoupper($this->record->commodity->value) }} for {{ $this->record->customer }}. Press the button when trucks can start loading against this order.</p>
                @if ($onSite->isNotEmpty())
                    <p class="bs-panel-lead">Already at the gate for this order: {{ $onSite->map(fn ($v) => $v->truck->plate)->join(', ') }}.</p>
                @endif
                <div class="bs-panel-actions">
                    <x-filament::button size="lg" :disabled="$readOnly" wire:click="openLoading" wire:loading.attr="disabled" wire:target="openLoading">Start loading</x-filament::button>
                </div>
                @break

            @case ('trucks')
                <h2>Trucks</h2>
                @if ($delivered > 0 && $remaining <= 0)
                    <div class="bs-finish" role="status">
                        <div>
                            <strong>All {{ number_format($ordered) }} kg is loaded.</strong>
                            @if ($onSite->isEmpty())
                                <span>No trucks are left on site. Complete the order to close it.</span>
                            @else
                                <span>Gate out the {{ $onSite->count() === 1 ? 'last truck' : 'trucks' }} still on site, then complete the order.</span>
                            @endif
                        </div>
                        @if ($onSite->isEmpty())
                            <x-filament::button size="lg" :disabled="$readOnly" wire:click="completeOrder" wire:loading.attr="disabled" wire:target="completeOrder">Complete the order</x-filament::button>
                        @endif
                    </div>
                @else
                    <p class="bs-panel-lead">Take each truck through the gate, the weighbridge and the loading bay. When one has left, gate in the next.</p>
                    @if ($delivered > 0 && $onSite->isEmpty())
                        {{-- Part-loaded and nobody at the gate: the order can still be closed, but quietly and after a question. --}}
                        <p class="bs-short-close">{{ number_format($delivered) }} of {{ number_format($ordered) }} kg loaded so far. Customer took less?
                            <button type="button" class="bs-link-btn" @disabled($readOnly) wire:click="completeOrder" wire:confirm="Close this order with {{ number_format($delivered) }} of {{ number_format($ordered) }} kg loaded?" wire:loading.attr="disabled" wire:target="completeOrder">Close the order short</button></p>
                    @endif
                @endif

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
                        <form wire:submit="gateIn">
                            {{ $this->gateInForm }}
                            <div class="bs-panel-actions">
                                <x-filament::button type="submit" :disabled="$readOnly" wire:loading.attr="disabled" wire:target="gateIn">Gate in truck</x-filament::button>
                                @if ($onSite->isNotEmpty())
                                    <x-filament::button color="gray" type="button" wire:click="backToTrucks">Back to the trucks on site</x-filament::button>
                                @endif
                            </div>
                        </form>
                        @break

                    @case ('weigh-in')
                        <h3 class="bs-sub">Weigh the empty truck</h3>
                        <form wire:submit="weighIn">
                            {{ $this->weighInForm }}
                            <div class="bs-panel-actions">
                                <x-filament::button type="submit" :disabled="$readOnly" wire:loading.attr="disabled" wire:target="weighIn">Weigh in</x-filament::button>
                            </div>
                        </form>
                        @break

                    @case ('start')
                        <h3 class="bs-sub">Move the truck into the loading bay</h3>
                        <p class="bs-panel-lead">{{ $current->truck->plate }} is weighed in at {{ number_format($current->weigh_in_kg) }} kg.</p>
                        <div class="bs-panel-actions">
                            <x-filament::button size="lg" :disabled="$readOnly" wire:click="startTruckLoading" wire:loading.attr="disabled" wire:target="startTruckLoading">Start loading</x-filament::button>
                        </div>
                        @break

                    @case ('load')
                        <div x-data="{ tab: @js($loads->isNotEmpty() ? 'weigh-out' : 'load') }">
                            <div class="bs-tabs" role="tablist" aria-label="Loading steps for this truck">
                                <button type="button" role="tab" class="bs-tab" :class="{ 'is-active': tab === 'load' }" :aria-selected="tab === 'load'" x-on:click="tab = 'load'">Load from a tank</button>
                                <button type="button" role="tab" class="bs-tab" :class="{ 'is-active': tab === 'weigh-out' }" :aria-selected="tab === 'weigh-out'" x-on:click="tab = 'weigh-out'">Weigh out</button>
                            </div>

                            <div x-show="tab === 'load'" role="tabpanel">
                                <form wire:submit="load">
                                    {{ $this->loadForm }}
                                    <div class="bs-panel-actions">
                                        <x-filament::button type="submit" :disabled="$readOnly" wire:loading.attr="disabled" wire:target="load">Load from tank</x-filament::button>
                                    </div>
                                </form>
                                @if ($loads->isNotEmpty())
                                    <div class="bs-list">
                                        <h3>Loaded onto this truck</h3>
                                        <div class="bs-table-wrap">
                                            <table class="bs-table">
                                                <thead><tr><th scope="col">When</th><th scope="col">Tank</th><th scope="col" class="num">Quantity (kg)</th></tr></thead>
                                                <tbody>
                                                    @foreach ($loads as $m)
                                                        <tr>
                                                            <td>{{ \App\Support\KenyaTime::of($m->occurred_at)->format('d M H:i') }}</td>
                                                            <td>{{ $m->tank?->code ?? '?' }}</td>
                                                            <td class="num">{{ number_format($m->gross_kg) }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <div x-show="tab === 'weigh-out'" x-cloak role="tabpanel">
                                <p class="bs-panel-lead">When the truck is full, weigh it on the way out.</p>
                                <form wire:submit="weighOut">
                                    {{ $this->weighOutForm }}
                                    <div class="bs-panel-actions">
                                        <x-filament::button type="submit" :disabled="$readOnly" wire:loading.attr="disabled" wire:target="weighOut">Weigh out</x-filament::button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        @break
                    @case ('gate-out')
                        <h3 class="bs-sub">Release the truck</h3>
                        <p class="bs-panel-lead">{{ $current->truck->plate }} has been weighed out. The last step of this visit is to let it leave.</p>
                        <div class="bs-panel-actions">
                            <x-filament::button size="lg" :disabled="$readOnly" wire:click="gateOut" wire:loading.attr="disabled" wire:target="gateOut">Gate out</x-filament::button>
                        </div>
                        @break
                @endswitch

                <div class="bs-panel-actions bs-panel-foot">
                    @if ($lane !== 'gate-in')
                        <x-filament::button color="gray" type="button" wire:click="startGateIn">Gate in another truck</x-filament::button>
                    @endif
                </div>
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
