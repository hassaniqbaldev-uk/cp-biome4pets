<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
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
            // Custom reset/SET-password page: shows the password requirements upfront
            // (helperText) for both the welcome-link and forgot-password flows.
            //
            // MUST use the named `resetAction:` argument. passwordReset()'s FIRST
            // positional param is the REQUEST (forgot-password) page; passing the
            // custom ResetPassword there wired the set-password form — whose email
            // field is intentionally DISABLED (the account is fixed by the token) —
            // onto the forgot-password route, so users couldn't type their email to
            // request a link. The request page must stay Filament's default
            // (RequestPasswordReset, editable email); only the reset page is custom.
            ->passwordReset(resetAction: \App\Filament\Pages\Auth\ResetPassword::class)
            ->brandName('Biome4Pets Portal')
            // The white logo is invisible on the light panel. Use the coloured
            // logo for light mode and the white one for dark mode so it reads in
            // both. (Filament swaps these on the .dark class automatically.)
            ->brandLogo('/images/biome4pets-logo.png')
            ->darkModeBrandLogo('/images/biome4pets-logo-white.png')
            ->brandLogoHeight('3rem')
            ->favicon('/favicon.svg')
            ->colors([
                'primary' => Color::hex('#4654A4'),
            ])
            ->navigationGroups([
                'Operations',
                'Catalog & Plans',
                'System',
            ])
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                fn (): View => view('filament.sidebar-logout'),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('partials.feedbucket')->render(),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): string => view('partials.favicons')->render(),
            )
            // Defence-in-depth noindex on every admin page (incl. the login). The
            // global X-Robots-Tag header is the authoritative control; this is a
            // belt-and-braces <meta> tag.
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): string => '<meta name="robots" content="noindex, nofollow, noarchive">',
            )
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn (): View => view('filament.footer-version'),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
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
                // Friendly "you're already signed in" page when a logged-in user
                // opens a set-password / reset link (instead of a blocked redirect).
                \App\Http\Middleware\PasswordResetWhileAuthenticated::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
