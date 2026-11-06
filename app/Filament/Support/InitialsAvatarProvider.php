<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Initials in a Bulkstream-ink circle, drawn locally as an SVG. The default provider fetches
 * pictures from a third-party site, which shows as a broken image whenever that site is unreachable.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = collect(explode(' ', trim((string) Filament::getNameForDefaultAvatar($record))))
            ->map(fn (string $part) => mb_strtoupper(mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $part), 0, 1)))
            ->filter()
            ->take(2)
            ->join('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#1b2f36"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" fill="#fff" font-family="Montserrat,Arial,sans-serif" font-size="26" font-weight="600">'
            .e($initials ?: '?').'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
