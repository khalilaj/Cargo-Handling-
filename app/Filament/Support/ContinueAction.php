<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

/**
 * The one forward button on voyage, order and truck-visit screens. It never opens a window:
 * it goes to the wizard page, which opens on the record's real current step.
 */
class ContinueAction
{
    /**
     * @param  class-string  $resource  the resource that owns the 'run' page
     * @param  callable(Model): ?string  $target  which page and record the button opens (default: the record itself)
     * @param  bool  $iconOnly  a compact icon button for table rows
     */
    public static function make(string $resource, ?callable $target = null, bool $iconOnly = false): Action
    {
        $action = Action::make('continue')
            ->label(fn (Model $record) => 'Continue: '.WorkflowSteps::continueLabel($record))
            ->icon('heroicon-o-arrow-right-circle')->color('primary')
            ->visible(fn (Model $record) => WorkflowSteps::continueLabel($record) !== null)
            ->authorize(fn () => auth()->user()?->role->canOperate() ?? false)   // hidden AND blocked for read-only roles
            ->url(fn (Model $record) => $target ? $target($record) : $resource::getUrl('run', ['record' => $record]));

        // In a table the button is an icon with the full wording as its tooltip, so every row has the same width.
        return $iconOnly
            ? $action->iconButton()->tooltip(fn (Model $record) => 'Continue: '.WorkflowSteps::continueLabel($record))
            : $action;
    }
}
