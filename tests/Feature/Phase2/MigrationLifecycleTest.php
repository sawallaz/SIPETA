<?php

namespace Tests\Feature\Phase2;

use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Migration lifecycle + seeder idempotency (Phase 2.5).
 *
 * Uses DatabaseMigrations (NOT RefreshDatabase): rollback inside a wrapped
 * transaction would defeat the real migrate/rollback ordering we want to
 * exercise, and FK enforcement needs a real connection.
 */
class MigrationLifecycleTest extends Phase2TestCase
{
    public function test_migrate_fresh_produces_all_tables(): void
    {
        $tables = [
            'settings', 'kartu_keluarga', 'penduduk', 'kk_anggota', 'kk_photos',
            'ocr_jobs', 'backup_logs', 'audit_logs', 'religions', 'educations',
            'occupations', 'area_units', 'rts',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} after migrate:fresh.");
        }
    }

    public function test_full_rollback_removes_all_domain_tables(): void
    {
        // Roll back every migration (including framework base migrations).
        Artisan::call('migrate:reset', ['--force' => true]);

        $domainTables = [
            'settings', 'kartu_keluarga', 'penduduk', 'kk_anggota', 'kk_photos',
            'ocr_jobs', 'backup_logs', 'audit_logs', 'religions', 'educations',
            'occupations', 'area_units', 'rts',
        ];

        foreach ($domainTables as $table) {
            $this->assertFalse(Schema::hasTable($table), "Expected {$table} gone after migrate:reset.");
        }
    }

    public function test_migrate_after_rollback_restores_schema(): void
    {
        Artisan::call('migrate:reset', ['--force' => true]);
        Artisan::call('migrate', ['--force' => true]);

        $this->assertTrue(Schema::hasTable('penduduk'));
        $this->assertTrue(Schema::hasTable('kartu_keluarga'));
        $this->assertTrue(Schema::hasTable('settings'));
    }

    public function test_seeder_is_idempotent(): void
    {
        // DatabaseMigrations already seeded "up" once (via the default seed in phpunit? no:
        // DatabaseMigrations does NOT auto-seed). Seed explicitly, then re-seed.
        $this->seed();

        $kkCount1 = KartuKeluarga::count();
        $pendudukCount1 = Penduduk::count();
        $religionCount1 = Religion::count();
        $settingsCount1 = Setting::count();

        // Re-run seeders: idempotent seeders must not duplicate rows.
        $this->seed();

        $this->assertSame($kkCount1, KartuKeluarga::count());
        $this->assertSame($pendudukCount1, Penduduk::count());
        $this->assertSame($religionCount1, Religion::count());
        $this->assertSame($settingsCount1, Setting::count());

        // Singleton settings stays at exactly one row.
        $this->assertSame(1, Setting::count());
    }
}
