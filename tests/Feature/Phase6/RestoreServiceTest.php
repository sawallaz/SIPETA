<?php

namespace Tests\Feature\Phase6;

use App\Exceptions\RestoreException;
use App\Models\Setting;
use App\Services\RestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDatabaseImporter;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 6.3 — Restore from ZIP backup. Verifies the service honours
 * FR-BR-04 (validate ZIP integrity before applying), FR-BR-05 (require explicit
 * confirmation before applying), and FR-BR-06 (advise restarting afterwards),
 * plus re-applying the SQL dump, settings singleton, and KK photos.
 */
class RestoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeDatabaseImporter $importer;

    private RestoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(RestoreService::DISK);
        Storage::fake(RestoreService::PHOTO_DISK);
        $this->importer = new FakeDatabaseImporter;
        $this->service = new RestoreService($this->importer);
    }

    private function putZip(string $filename, array $entries): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sipeta_restore_');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        $zip->close();
        Storage::disk(RestoreService::DISK)->put($filename, (string) file_get_contents($tmp));
        @unlink($tmp);
    }

    private function baseSettings(): array
    {
        return [
            'kelurahan_name' => 'Kelurahan Tanete',
            'kecamatan_name' => 'Kecamatan Polewali',
            'kabupaten_name' => 'Kabupaten Polewali Mandar',
            'province_name' => 'Sulawesi Barat',
            'backup_path' => storage_path('backups'),
        ];
    }

    public function test_restore_requires_explicit_confirmation(): void
    {
        $result = $this->service->restore('backup_2026-08-07_153045.zip');

        $this->assertTrue($result->isConfirmationRequired());
        $this->assertFalse($result->restartRequired);
        $this->assertSame('backup_2026-08-07_153045.zip', $result->filename);
        $this->assertSame([], $this->importer->applied);
    }

    public function test_missing_archive_throws_before_applying(): void
    {
        try {
            $this->service->restore('ghost.zip', null, true);
            $this->fail('Expected RestoreException was not thrown.');
        } catch (RestoreException $e) {
            $this->assertStringContainsString('tidak ditemukan', $e->getMessage());
        }

        $this->assertSame([], $this->importer->applied);
    }

    public function test_corrupt_archive_throws_before_applying(): void
    {
        Storage::disk(RestoreService::DISK)->put('broken.zip', 'THIS IS NOT A ZIP');

        try {
            $this->service->restore('broken.zip', null, true);
            $this->fail('Expected RestoreException was not thrown.');
        } catch (RestoreException $e) {
            $this->assertStringContainsString('korup', $e->getMessage());
        }

        $this->assertSame([], $this->importer->applied);
    }

    public function test_archive_missing_database_sql_throws_before_applying(): void
    {
        $this->putZip('incomplete.zip', ['settings.json' => '[]']);

        try {
            $this->service->restore('incomplete.zip', null, true);
            $this->fail('Expected RestoreException was not thrown.');
        } catch (RestoreException $e) {
            $this->assertStringContainsString('database.sql', $e->getMessage());
        }

        $this->assertSame([], $this->importer->applied);
    }

    public function test_successful_restore_applies_sql_settings_and_photos(): void
    {
        Setting::create($this->baseSettings());

        $this->putZip('backup_2026-08-07_153045.zip', [
            'database.sql' => 'CREATE TABLE example (id INT);',
            'settings.json' => json_encode([
                'id' => 1,
                'kelurahan_name' => 'Kelurahan Pulih',
                'kecamatan_name' => 'Kecamatan Pulih',
                'kabupaten_name' => 'Kabupaten Pulih',
                'province_name' => 'Provinsi Pulih',
                'logo_path' => null,
                'backup_path' => '/backups',
                'created_at' => '2026-01-01 00:00:00',
            ]),
            'kk/photo-a.jpg' => 'PHOTO_A',
            'kk/photo-b.jpg' => 'PHOTO_B',
        ]);

        $result = $this->service->restore('backup_2026-08-07_153045.zip', null, true);

        // FR-BR-06: after a restore the operator must restart the application.
        $this->assertTrue($result->isRestored());
        $this->assertTrue($result->restartRequired);

        $this->assertSame(['CREATE TABLE example (id INT);'], $this->importer->applied);

        $settings = Setting::query()->first();
        $this->assertSame(1, Setting::query()->count());
        $this->assertSame('Kelurahan Pulih', $settings->kelurahan_name);

        $this->assertTrue(Storage::disk(RestoreService::PHOTO_DISK)->exists('kk/photo-a.jpg'));
        $this->assertSame('PHOTO_A', Storage::disk(RestoreService::PHOTO_DISK)->get('kk/photo-a.jpg'));
        $this->assertSame('PHOTO_B', Storage::disk(RestoreService::PHOTO_DISK)->get('kk/photo-b.jpg'));
    }

    public function test_empty_settings_json_skips_settings_upsert(): void
    {
        $this->putZip('backup_empty_settings.zip', [
            'database.sql' => 'CREATE TABLE example (id INT);',
            'settings.json' => '[]',
        ]);

        $result = $this->service->restore('backup_empty_settings.zip', null, true);

        $this->assertTrue($result->isRestored());
        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(['CREATE TABLE example (id INT);'], $this->importer->applied);
    }

    public function test_importer_failure_applies_nothing_else(): void
    {
        Setting::create($this->baseSettings());
        $failing = new RestoreService(FakeDatabaseImporter::failing());

        $this->putZip('bad.zip', [
            'database.sql' => 'CREATE TABLE example (id INT);',
            'settings.json' => json_encode([
                'kelurahan_name' => 'Kelurahan Hantu',
                'kecamatan_name' => 'KB',
                'kabupaten_name' => 'KP',
                'province_name' => 'PR',
                'backup_path' => '/x',
            ]),
            'kk/photo-a.jpg' => 'PHOTO_A',
        ]);

        try {
            $failing->restore('bad.zip', null, true);
            $this->fail('Expected RestoreException was not thrown.');
        } catch (RestoreException $e) {
            $this->assertStringContainsString('simulated mysql import failure', $e->getMessage());
        }

        // The SQL dump is applied first; a failure stops settings + photos.
        $this->assertSame('Kelurahan Tanete', Setting::query()->first()->kelurahan_name);
        Storage::disk(RestoreService::PHOTO_DISK)->assertDirectoryEmpty('');
    }
}
