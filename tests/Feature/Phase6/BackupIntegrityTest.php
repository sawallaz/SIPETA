<?php

namespace Tests\Feature\Phase6;

use App\Services\BackupIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 6.6 — Backup integrity check (FR-MED-04 / F-MED-04).
 *
 * Verifies BackupIntegrityService reports every archive on the `db_backups`
 * disk as healthy (valid ZIP with readable `database.sql` + `settings.json`)
 * or corrupt (unopenable ZIP / missing or unreadable required entries), that
 * the check is strictly read-only, and that the `backup:integrity-check`
 * command surfaces the results and fails the exit code when any archive is
 * corrupt (the launch hook, NFR-REL-01).
 */
class BackupIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private BackupIntegrityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(BackupIntegrityService::DISK);
        $this->service = new BackupIntegrityService;
    }

    public function test_check_all_returns_empty_when_no_archives(): void
    {
        $this->assertSame([], $this->service->checkAll());
    }

    public function test_healthy_archive_is_ok(): void
    {
        $this->putZip('backup_2026-08-07_100000.zip', [
            'database.sql' => 'CREATE TABLE example (id INT);',
            'settings.json' => '[]',
        ]);

        $result = $this->service->check('backup_2026-08-07_100000.zip');

        $this->assertTrue($result->isOk());
        $this->assertFalse($result->isCorrupt());
        $this->assertSame([], $result->issues);
    }

    public function test_non_zip_bytes_are_corrupt(): void
    {
        Storage::disk(BackupIntegrityService::DISK)->put('backup_2026-08-07_100000.zip', 'NOT A ZIP');

        $result = $this->service->check('backup_2026-08-07_100000.zip');

        $this->assertTrue($result->isCorrupt());
        $this->assertStringContainsString('ZIP', $result->issues[0]);
    }

    public function test_archive_missing_database_sql_is_corrupt(): void
    {
        $this->putZip('backup_2026-08-07_100000.zip', [
            'settings.json' => '[]',
        ]);

        $result = $this->service->check('backup_2026-08-07_100000.zip');

        $this->assertTrue($result->isCorrupt());
        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('database.sql', $result->issues[0]);
    }

    public function test_archive_missing_settings_json_is_corrupt(): void
    {
        $this->putZip('backup_2026-08-07_100000.zip', [
            'database.sql' => 'CREATE TABLE example (id INT);',
        ]);

        $result = $this->service->check('backup_2026-08-07_100000.zip');

        $this->assertTrue($result->isCorrupt());
        $this->assertCount(1, $result->issues);
        $this->assertStringContainsString('settings.json', $result->issues[0]);
    }

    public function test_archive_missing_both_required_entries_reports_both(): void
    {
        $this->putZip('backup_2026-08-07_100000.zip', [
            'kk/photo.jpg' => 'BYTES',
        ]);

        $result = $this->service->check('backup_2026-08-07_100000.zip');

        $this->assertTrue($result->isCorrupt());
        $this->assertCount(2, $result->issues);
    }

    public function test_check_all_ignores_non_zip_files(): void
    {
        Storage::disk(BackupIntegrityService::DISK)->put('catatan.txt', 'bukan backup');
        $this->putZip('backup_2026-08-07_100000.zip', [
            'database.sql' => 'CREATE TABLE example (id INT);',
            'settings.json' => '[]',
        ]);

        $results = $this->service->checkAll();

        $this->assertCount(1, $results);
        $this->assertSame('backup_2026-08-07_100000.zip', $results[0]->filename);
        $this->assertTrue($results[0]->isOk());
    }

    public function test_check_all_mixes_healthy_and_corrupt(): void
    {
        $this->putZip('backup_2026-08-07_100000.zip', [
            'database.sql' => 'CREATE TABLE example (id INT);',
            'settings.json' => '[]',
        ]);
        Storage::disk(BackupIntegrityService::DISK)->put('backup_2026-08-07_110000.zip', 'CORRUPT');

        $results = $this->service->checkAll();

        $this->assertCount(2, $results);
        $this->assertTrue(collect($results)->firstWhere('filename', 'backup_2026-08-07_100000.zip')->isOk());
        $this->assertTrue(collect($results)->firstWhere('filename', 'backup_2026-08-07_110000.zip')->isCorrupt());
    }

    public function test_check_throws_for_missing_archive(): void
    {
        try {
            $this->service->check('backup_2026-08-07_100000.zip');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tidak ditemukan', $e->getMessage());
        }
    }

    public function test_command_reports_no_archives_as_success(): void
    {
        $this->artisan('backup:integrity-check')
            ->expectsOutputToContain('Belum ada arsip backup')
            ->assertExitCode(0);
    }

    public function test_command_reports_healthy_archive_and_exits_zero(): void
    {
        $this->putZip('backup_2026-08-07_100000.zip', [
            'database.sql' => 'CREATE TABLE example (id INT);',
            'settings.json' => '[]',
        ]);

        $this->artisan('backup:integrity-check')
            ->expectsOutputToContain('SEHAT')
            ->expectsOutputToContain('1 arsip sehat, 0 arsip bermasalah')
            ->assertExitCode(0);
    }

    public function test_command_exits_failure_when_any_archive_is_corrupt(): void
    {
        $this->putZip('backup_2026-08-07_100000.zip', [
            'database.sql' => 'CREATE TABLE example (id INT);',
            'settings.json' => '[]',
        ]);
        Storage::disk(BackupIntegrityService::DISK)->put('backup_2026-08-07_110000.zip', 'CORRUPT');

        $this->artisan('backup:integrity-check')
            ->expectsOutputToContain('RUSAK')
            ->expectsOutputToContain('1 arsip sehat, 1 arsip bermasalah')
            ->assertExitCode(1);
    }

    private function putZip(string $filename, array $entries): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sipeta_integrity_');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        Storage::disk(BackupIntegrityService::DISK)->put($filename, (string) file_get_contents($tmp));
        @unlink($tmp);
    }
}
