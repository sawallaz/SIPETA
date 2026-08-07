<?php

namespace App\Providers;

use App\Services\DatabaseDumper;
use App\Services\MysqldumpDatabaseDumper;
use App\Services\OcrEngine;
use App\Services\TesseractOcrEngine;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
