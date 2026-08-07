<?php

namespace Tests\Feature\Phase6;

use App\Filament\Pages\Backup;
use App\Models\Setting;
use App\Models\User;
use App\Services\DatabaseDumper;
use App\Services\DatabaseImporter;
use App\Services\RestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeDatabaseDumper;
use Tests\Support\FakeDatabaseImporter;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 6.4 — operator-facing Backup & Restore page. Verifies the page wiring
 * against the Phase 6.2 BackupService and the Phase 6.3 RestoreService:
 * the archive list, the "Buat Backup" action (workflow §14), the two-step
 * restore flow (§15) with the FR-BR-05 explicit confirmation gate, the
 * FR-BR-04 integrity-failure handling, and the FR-BR-06 restart advice.
 */
class BackupPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected FakeDatabaseImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(RestoreService::DISK);
        Storage::fake(RestoreService::PHOTO_DISK);

        $this->importer = new FakeDatabaseImporter;
        $this->app->instance(DatabaseDumper::class, new FakeDatabaseDumper);
        $this->app->instance(DatabaseImporter::class, $this->importer);

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_backup_page_lists_stored_archives(): void
    {
        $this->putZip('backup_2026-08-07_100000.zip', [
            'database.sql' => 'CREATE TABLE example (id INT);',
            'settings.json' => '[]',
        ]);

        $this->get(Backup::getUrl())
            ->assertOk()
            ->assertSee('Backup & Restore')
            ->assertSee('Buat Backup')
            ->assertSee('backup_2026-08-07_100000.zip')
            ->assertSee('Pulihkan');
    }

    public function test_create_backup_action_builds_archive(): void
    {
        Livewire::test(Backup::class)
            ->callAction('buatBackup')
            ->assertNotified('Backup berhasil');

        $files = Storage::disk(RestoreService::DISK)->files();
        $this->assertNotEmpty($files, 'Expected the backup archive to be created.');
        $this->assertMatchesRegularExpression(
            '/^backup_\d{4}-\d{2}-\d{2}_\d{6}\.zip$/',
            basename($files[0]),
            'Expected the FR-BR-02 backup filename pattern.',
        );
    }

    public function test_restore_requires_confirmation_then_applies(): void
    {
        $this->putZip('backup_2026-08-07_110000.zip', [
            'database.sql' => 'INSERT INTO example (id) VALUES (1);',
            'settings.json' => json_encode([
                'id' => 1,
                'kelurahan_name' => 'Kelurahan Pulih',
                'kecamatan_name' => 'Kec. Pulih',
                'kabupaten_name' => 'Kab. Pulih',
                'province_name' => 'Prov. Pulih',
                'backup_path' => '/pulih',
            ]),
            'kk/pulih.jpg' => 'PULIH_PHOTO',
        ]);

        Setting::create([
            'kelurahan_name' => 'Kelurahan Asli',
            'kecamatan_name' => 'Kec. Asli',
            'kabupaten_name' => 'Kab. Asli',
            'province_name' => 'Prov. Asli',
            'backup_path' => '/asli',
        ]);

        Livewire::test(Backup::class)
            ->call('requestRestore', 'backup_2026-08-07_110000.zip')
            ->assertSet('restoreCandidate', 'backup_2026-08-07_110000.zip')
            ->call('confirmRestore')
            ->assertNotified('Pemulihan selesai')
            ->assertSet('restoreCandidate', null);

        // The dump was applied, the settings singleton restored, and the
        // archive's KK photo written back (FR-BR-04 validation passed).
        $this->assertSame(['INSERT INTO example (id) VALUES (1);'], $this->importer->applied);
        $this->assertSame('Kelurahan Pulih', Setting::query()->first()?->kelurahan_name);
        Storage::disk(RestoreService::PHOTO_DISK)->assertExists('kk/pulih.jpg');
        $this->assertSame('PULIH_PHOTO', Storage::disk(RestoreService::PHOTO_DISK)->get('kk/pulih.jpg'));
    }

    public function test_restore_surfaces_corrupt_archive_without_applying(): void
    {
        Storage::disk(RestoreService::DISK)->put('backup_2026-08-07_120000.zip', 'NOT A ZIP');

        Livewire::test(Backup::class)
            ->call('requestRestore', 'backup_2026-08-07_120000.zip')
            ->call('confirmRestore')
            ->assertNotified('Pemulihan gagal')
            ->assertSet('restoreCandidate', 'backup_2026-08-07_120000.zip');

        $this->assertSame([], $this->importer->applied);
    }

    public function test_restore_request_rejects_non_zip_archive(): void
    {
        Livewire::test(Backup::class)
            ->call('requestRestore', 'catatan.txt')
            ->assertNotified('Arsip tidak valid')
            ->assertSet('restoreCandidate', null);
    }

    private function putZip(string $filename, array $entries): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sipeta_page_');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        Storage::disk(RestoreService::DISK)->put($filename, (string) file_get_contents($tmp));
        @unlink($tmp);
    }
}
