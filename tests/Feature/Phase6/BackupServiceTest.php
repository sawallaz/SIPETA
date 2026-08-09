<?php

namespace Tests\Feature\Phase6;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Exceptions\BackupException;
use App\Models\BackupLog;
use App\Models\KkPhoto;
use App\Models\Setting;
use App\Models\User;
use App\Services\BackupService;
use Carbon\Carbon;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDatabaseDumper;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 6.2 — ZIP backup. Verifies the service honours FR-BR-01 (ZIP with SQL
 * dump + KK photos + settings), FR-BR-02 (backup_YYYY-MM-DD_HHMMSS.zip),
 * FR-BR-03 (never overwrite an existing archive), and FR-AUD-01 (logging).
 */
class BackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private BackupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(BackupService::DISK);
        Storage::fake('kk_uploads');
        $this->service = new BackupService(new FakeDatabaseDumper('DUMMY_SQL'));
    }

    public function test_filename_uses_fr_br_02_pattern(): void
    {
        $this->assertSame(
            'backup_2026-08-07_153045.zip',
            $this->service->filename(Carbon::parse('2026-08-07 15:30:45')),
        );
    }

    public function test_create_writes_zip_with_sql_and_appends_success_log(): void
    {
        $result = $this->service->create();

        $this->assertTrue($result->isSuccess());
        $this->assertStringStartsWith('backup_', $result->filename);
        $this->assertStringEndsWith('.zip', $result->filename);
        $this->assertGreaterThan(0, $result->size);

        $this->assertTrue(Storage::disk(BackupService::DISK)->exists($result->filename));

        $log = BackupLog::query()->first();
        $this->assertSame($result->filename, $log->filename);
        $this->assertSame(BackupType::MANUAL, $log->backup_type);
        $this->assertSame(BackupStatus::SUCCESS, $log->backup_status);
        $this->assertSame($result->size, $log->backup_size);
        $this->assertNotNull($log->started_at);
        $this->assertNotNull($log->finished_at);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk(BackupService::DISK)->path($result->filename)));
        $this->assertSame('DUMMY_SQL', $zip->getFromName('database.sql'));
        $this->assertSame('[]', $zip->getFromName('settings.json'));
        $zip->close();
    }

    public function test_backup_includes_settings_and_kk_photos(): void
    {
        Setting::create([
            'kelurahan_name' => 'Kelurahan Tanete',
            'kecamatan_name' => 'Kecamatan Polewali',
            'kabupaten_name' => 'Kabupaten Polewali Mandar',
            'province_name' => 'Sulawesi Barat',
            'backup_path' => storage_path('backups'),
        ]);
        $photo = KkPhoto::factory()->create([
            'storage_disk' => 'kk_uploads',
            'storage_path' => 'kk/photo-1.jpg',
            'stored_filename' => 'stored-1.jpg',
        ]);
        Storage::disk('kk_uploads')->put('kk/photo-1.jpg', 'PHOTO_BYTES');
        KkPhoto::factory()->create([
            'storage_disk' => 'kk_uploads',
            'storage_path' => 'kk/missing.jpg',
            'stored_filename' => 'stored-missing.jpg',
        ]);

        $result = $this->service->create();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk(BackupService::DISK)->path($result->filename)));
        $this->assertStringContainsString('Kelurahan Tanete', $zip->getFromName('settings.json'));
        $this->assertSame('PHOTO_BYTES', $zip->getFromName('kk/'.$photo->stored_filename));
        // A photo whose stored file is absent is skipped without failing the backup.
        $this->assertFalse($zip->getFromName('kk/stored-missing.jpg'));
        $zip->close();
    }

    public function test_create_never_overwrites_existing_backup(): void
    {
        Carbon::setTestNow('2026-08-07 15:30:45');
        $filename = $this->service->filename();
        Storage::disk(BackupService::DISK)->put($filename, 'EXISTING');

        try {
            $result = $this->service->create();

            $this->assertTrue($result->isDuplicate());
            $this->assertSame($filename, $result->filename);
            $this->assertSame('EXISTING', Storage::disk(BackupService::DISK)->get($filename));
            $this->assertSame(0, BackupLog::query()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_failed_dump_logs_failed_and_throws(): void
    {
        $failing = new BackupService(FakeDatabaseDumper::failing());

        try {
            $failing->create();
            $this->fail('Expected BackupException was not thrown.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('simulated mysqldump failure', $e->getMessage());
        }

        $log = BackupLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(BackupStatus::FAILED, $log->backup_status);
        $this->assertSame(0, $log->backup_size);
        $this->assertStringContainsString('simulated mysqldump failure', $log->message);
        $this->assertNotNull($log->finished_at);
        $this->assertSame([], Storage::disk(BackupService::DISK)->allFiles());
    }

    public function test_disk_write_failure_is_never_a_false_success(): void
    {
        // The db_backups disk is configured throw=false: a failed write (full
        // disk, permissions) returns false instead of throwing. Point the disk
        // at a real unwritable root so writeStream() fails for real, and assert
        // the attempt is logged FAILED — never SUCCESS (NFR-REL-01).
        config(['filesystems.disks.db_backups' => [
            'driver' => 'local',
            'root' => '/proc',
            'throw' => false,
        ]]);
        $this->app->instance('filesystem', new FilesystemManager($this->app));
        // The facade caches the resolved manager (setUp's Storage::fake); drop
        // it so the re-pointed db_backups disk above actually resolves.
        Storage::clearResolvedInstances();

        try {
            $this->service->create();
            $this->fail('Expected BackupException was not thrown on a failed disk write.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('tidak dapat disimpan', $e->getMessage());
        }

        $log = BackupLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(BackupStatus::FAILED, $log->backup_status);
        $this->assertSame(0, $log->backup_size);
    }

    public function test_operator_is_recorded(): void
    {
        $operator = User::factory()->create();

        $this->service->create($operator);

        $this->assertSame($operator->id, BackupLog::query()->first()->operator_id);
    }
}
