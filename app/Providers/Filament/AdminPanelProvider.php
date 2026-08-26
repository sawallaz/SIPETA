<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\EnsureSuperAdminExists;
use App\Http\Middleware\FilamentAuthenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('SIPETA')
            ->brandLogo(fn () => view('filament.components.brand-logo'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('favicon.svg'))
            ->maxContentWidth(Width::Full)
            ->login(Login::class)
            ->passwordReset()
            ->profile(isSimple: false)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('16rem')
            ->collapsedSidebarWidth('4.5rem')
            ->colors([
                'primary' => [
                    50 => '#f4f8f3',
                    100 => '#e5eee3',
                    200 => '#cdddc9',
                    300 => '#a8c3a2',
                    400 => '#7ba273',
                    500 => '#598451',
                    600 => '#456b4f', // Color main target #456B4F
                    700 => '#385640',
                    800 => '#2f4635',
                    900 => '#283b2d',
                    950 => '#141f17',
                ],
            ])
            ->navigationGroups([
                'Kependudukan',
                'Master Data',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                function (): HtmlString {
                    $ip = request()->server('SERVER_ADDR');
                    if (empty($ip) || $ip === '0.0.0.0' || $ip === '127.0.0.1' || $ip === '::1') {
                        $ip = gethostbyname(gethostname());
                    }
                    $url = "http://{$ip}:8100";

                    return new HtmlString('
                        <div style="display:inline-flex;align-items:center;gap:8px;margin-right:12px;padding:5px 12px;background:#1e293b;border:1px solid #0284c7;border-radius:6px;color:#ffffff;font-size:12px;font-weight:600;z-index:9999;">
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#22c55e;"></span>
                            <span style="font-family:monospace;color:#38bdf8;">LAN: ' . $url . '</span>
                            <button type="button" onclick="navigator.clipboard.writeText(\'' . $url . '\'); alert(\'URL ' . $url . ' berhasil disalin!\');" style="background:#0284c7;color:#fff;border:none;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:11px;">Salin</button>
                        </div>
                    ');
                }
            )
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
                EnsureSuperAdminExists::class,
            ])
            ->authMiddleware([
                FilamentAuthenticate::class,
            ]);
    }
}
