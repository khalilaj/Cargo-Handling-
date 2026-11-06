<?php

namespace App\Providers\Filament;

use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Bulkstream Cargo Handling')
            ->brandLogo(asset('images/bulkstream-logo.png'))
            ->darkModeBrandLogo(asset('images/bulkstream-logo-white.png'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('images/favicon.png'))
            ->font('Mulish', provider: GoogleFontProvider::class)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="'.e(asset('css/bulkstream.css')).'?v=1">')
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE, fn (): string => '<div class="bs-login-tag">Terminal operations</div>')
            ->databaseNotifications(false)
            // The bell sits in the top bar, before the user menu, for roles that act on alerts.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => Blade::render('@livewire(\'alerts-bell\')'))
            // Daily work first. Rarely used areas start collapsed, and each role only gets the groups it may open.
            ->navigationGroups([
                NavigationGroup::make('Operations'),
                NavigationGroup::make('Stock'),
                NavigationGroup::make('Reports'),
                NavigationGroup::make('Registers')->collapsed(),
                NavigationGroup::make('Administration')->collapsed(),
            ])
            ->colors([
                // Bulkstream ink (logo #153037, website #1B2F36) as an explicit scale: 600 is the button colour in light mode,
                // 500 and 400 stay readable on the dark theme.
                'primary' => [
                    50 => '#f2f6f7', 100 => '#e1eaed', 200 => '#c3d4da', 300 => '#9ab6c0', 400 => '#6f95a3',
                    500 => '#3f6b79', 600 => '#1b2f36', 700 => '#153037', 800 => '#10262b', 900 => '#0b1c20', 950 => '#071215',
                ],
                'gray' => Color::Slate,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
