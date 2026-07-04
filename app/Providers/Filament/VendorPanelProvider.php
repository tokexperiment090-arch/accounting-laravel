<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Vendor self-service portal. Guard `vendor`; no tenancy. Read-only resources
 * are discovered from app/Filament/Vendor/Resources. Vendor's auth username is
 * the standard `email` column, so the default Filament login page works.
 */
class VendorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('vendor')
            ->path('vendor-portal')
            ->authGuard('vendor')
            ->login()
            ->brandName('Vendor Portal')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Vendor/Resources'), for: 'App\\Filament\\Vendor\\Resources')
            ->discoverPages(in: app_path('Filament/Vendor/Pages'), for: 'App\\Filament\\Vendor\\Pages')
            ->discoverWidgets(in: app_path('Filament/Vendor/Widgets'), for: 'App\\Filament\\Vendor\\Widgets')
            ->pages([
                Dashboard::class,
            ])
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
