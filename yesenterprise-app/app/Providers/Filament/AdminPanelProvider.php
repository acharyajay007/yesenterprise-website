<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\WebsiteOverview;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Irajul\FilamentShadcnTheme\Enums\BaseColor;
use Irajul\FilamentShadcnTheme\Enums\MenuAccent;
use Irajul\FilamentShadcnTheme\Enums\Radius;
use Irajul\FilamentShadcnTheme\Enums\SidebarVariant;
use Irajul\FilamentShadcnTheme\Enums\StyleVariant;
use Irajul\FilamentShadcnTheme\Enums\SurfaceShadow;
use Irajul\FilamentShadcnTheme\Enums\ThemeColor;
use Irajul\FilamentShadcnTheme\FilamentShadcnThemePlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Yes Enterprise Workspace')
            ->brandLogo(fn () => view('components.business-brand', ['admin' => true]))
            ->brandLogoHeight('3.5rem')
            ->favicon(asset('assets/yes-enterprise-logo.png'))
            ->colors([
                'primary' => Color::Orange,
            ])
            ->defaultThemeMode(ThemeMode::Light)
            ->plugin(
                FilamentShadcnThemePlugin::make()
                    ->style(StyleVariant::Nova)
                    ->baseColor(BaseColor::Zinc)
                    ->themeColor(ThemeColor::Orange)
                    ->radius(Radius::Large)
                    ->menuAccent(MenuAccent::Subtle)
                    ->sidebarVariant(SidebarVariant::Inset)
                    ->surfaceShadow(SurfaceShadow::ExtraSmall)
            )
            ->assets([
                Css::make('yes-enterprise-workspace', asset('admin.css')),
            ])
            ->font('Inter', provider: LocalFontProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            ->navigationGroups(['Catalogue', 'Website', 'Inbox'])
            ->userMenuItems([
                Action::make('website')
                    ->label('View website')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (): string => route('home', ['locale' => 'en']))
                    ->openUrlInNewTab(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                WebsiteOverview::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
