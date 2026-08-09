<?php

namespace App\Providers;

use App\Services\DatabaseDumper;
use App\Services\DatabaseImporter;
use App\Services\MysqlClientDatabaseImporter;
use App\Services\MysqldumpDatabaseDumper;
use App\Services\OcrEngine;
use App\Services\TesseractOcrEngine;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // OCR pipeline engine (Phase 5.4). Tests override this binding with a
        // fake so the real Tesseract binary is never invoked by the suite.
        $this->app->bind(OcrEngine::class, TesseractOcrEngine::class);

        // Database dump for ZIP backups (Phase 6.2). Tests override this binding
        // with a fake so the real mysqldump client is never invoked by the suite.
        $this->app->bind(DatabaseDumper::class, MysqldumpDatabaseDumper::class);

        // Database import for restores (Phase 6.3). Tests override this binding
        // with a fake so the real mysql client is never invoked by the suite.
        $this->app->bind(DatabaseImporter::class, MysqlClientDatabaseImporter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase UI-4: load the scoped admin stylesheet after Filament's own
        // theme so sidebar/spacing/typography refinements take precedence
        // without shadowing the default. The SIDEBAR hooks are not needed here;
        // the stylesheet is injected for every panel page (dashboard included).
        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_AFTER,
            fn (): string => app(\Illuminate\Foundation\Vite::class)('resources/css/sipeta-admin.css')->toHtml(),
        );
    }
}
