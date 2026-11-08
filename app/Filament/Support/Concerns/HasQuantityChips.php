<?php

namespace App\Filament\Support\Concerns;

use Filament\Schemas\Components\View;

/**
 * Quick-pick buttons under a wizard's quantity field. The page says which amounts make sense right now
 * (quantityChips) and where its quantity is kept (quantityStatePath); this does the rest.
 */
trait HasQuantityChips
{
    /** @return list<array{label: string, kg: int}> */
    abstract public function quantityChips(): array;

    /** The Livewire property that holds the form's state, for example 'pumpData'. */
    abstract protected function quantityStatePath(): string;

    /** Only an amount that is on offer right now can be set, and only by someone who may press the step. */
    public function setQuantity(int $kg): void
    {
        abort_unless($this->canOperate(), 403);
        abort_unless(in_array($kg, array_column($this->quantityChips(), 'kg'), true), 422);

        data_set($this, $this->quantityStatePath().'.quantity_kg', $kg);
    }

    protected function quantityChipsComponent(): View
    {
        return View::make('filament.components.quantity-chips')
            ->viewData(fn () => ['chips' => $this->quantityChips(), 'enabled' => $this->canOperate()]);
    }
}
