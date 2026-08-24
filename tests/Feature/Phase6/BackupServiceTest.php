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
use App\Services\GoogleDriveClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDatabaseDumper;
use Tests\TestCase;
use ZipArchive;

class BackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private BackupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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

    public function test_drive_upload_uses_temporary_archive_and_cleans_it_after_success(): void
    {
        $admin = User::factory()->create();
        $drive = $this->mock(GoogleDriveClient::class);
        $uploadedPath = null;
        $drive->shouldReceive('ensureBackupFolder')->once()->andReturn(['id' => 'folder-1', 'name' => 'SIPETA Backup']);
        $drive->shouldReceive('upload')->once()->andReturnUsing(function (string $path, string $folder, string $filename, string $checksum) use (&$uploadedPath): array {
            $uploadedPath = $path;
            $this->assertFileExists($path);

            return [
                'id' => 'drive-file-1',
                'name' => $filename,
                'parents' => [$folder],
                'appProperties' => ['sipeta_checksum' => $checksum],
            ];
        });

        $result = $this->service->createToDrive($admin, $drive);

        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($uploadedPath);
        $this->assertFileDoesNotExist((string) $uploadedPath);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(BackupStatus::SUCCESS, BackupLog::query()->first()->backup_status);
        $this->assertSame(BackupType::MANUAL, BackupLog::query()->first()->backup_type);
    }

    public function test_archive_payload_is_available_during_upload_without_becoming_persistent(): void
    {
        Setting::create([
            'kelurahan_name' => 'Kelurahan Tanete',
            'kecamatan_name' => 'Kecamatan Polewali',
            'kabupaten_name' => 'Kabupaten Polewali Mandar',
            'province_name' => 'Sulawesi Barat',
        ]);
        $photo = KkPhoto::factory()->create([
            'storage_disk' => 'kk_uploads',
            'storage_path' => 'kk/photo-1.jpg',
            'stored_filename' => 'stored-1.jpg',
        ]);
        Storage::disk('kk_uploads')->put('kk/photo-1.jpg', 'PHOTO_BYTES');

        $drive = $this->mock(GoogleDriveClient::class);
        $drive->shouldReceive('ensureBackupFolder')->once()->andReturn(['id' => 'folder-1', 'name' => 'SIPETA Backup']);
        $drive->shouldReceive('upload')->once()->andReturnUsing(function (string $path) use ($photo): array {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $this->assertStringContainsString('Kelurahan Tanete', (string) $zip->getFromName('settings.json'));
            $this->assertSame('PHOTO_BYTES', $zip->getFromName('kk/'.$photo->stored_filename));
            $zip->close();

            return [
                'id' => 'drive-file-1',
                'name' => 'backup_2026-08-15_120000.zip',
                'parents' => ['folder-1'],
                'appProperties' => ['sipeta_checksum' => hash('sha256', 'payload')],
            ];
        });

        // Metadata is deliberately supplied by the callback; the service's
        // upload verification is covered by GoogleDriveIntegrationTest.
        try {
            $this->service->createToDrive(User::factory()->create(), $drive);
        } catch (BackupException $e) {
            $this->assertStringContainsString('Metadata backup', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_dump_logs_failed_and_cleans_temporary_archive(): void
    {
        $before = glob(sys_get_temp_dir().'/sipeta_backup_*') ?: [];
        $drive = $this->mock(GoogleDriveClient::class);
        $failing = new BackupService(FakeDatabaseDumper::failing());

        try {
            $failing->createToDrive(User::factory()->create(), $drive);
            $this->fail('Expected BackupException was not thrown.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('simulated database dump failure', $e->getMessage());
        }

        $after = glob(sys_get_temp_dir().'/sipeta_backup_*') ?: [];
        $this->assertSame($before, $after);
        $log = BackupLog::query()->first();
        $this->assertSame(BackupStatus::FAILED, $log->backup_status);
        $this->assertSame(0, $log->backup_size);
        $this->assertStringContainsString('simulated database dump failure', $log->message);
    }

    public function test_upload_failure_is_never_logged_as_success_and_cleans_archive(): void
    {
        $drive = $this->mock(GoogleDriveClient::class);
        $drive->shouldReceive('ensureBackupFolder')->once()->andReturn(['id' => 'folder-1', 'name' => 'SIPETA Backup']);
        $drive->shouldReceive('upload')->once()->andThrow(new BackupException('upload gagal'));

        try {
            $this->service->createToDrive(User::factory()->create(), $drive);
            $this->fail('Expected BackupException was not thrown.');
        } catch (BackupException $e) {
            $this->assertSame('upload gagal', $e->getMessage());
        }

        $this->assertSame(BackupStatus::FAILED, BackupLog::query()->first()->backup_status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_duplicate_backup_request_is_locked(): void
    {
        $lock = Cache::lock('sipeta:google-drive-backup', 60);
        $this->assertTrue($lock->get());

        try {
            $this->expectExceptionMessage('Backup sedang diproses');
            $this->service->createToDrive(User::factory()->create(), $this->mock(GoogleDriveClient::class));
        } finally {
            $lock->release();
        }
    }

    public function test_operator_is_recorded_in_google_drive_history(): void
    {
        $operator = User::factory()->create();
        $drive = $this->mock(GoogleDriveClient::class);
        $drive->shouldReceive('ensureBackupFolder')->once()->andReturn(['id' => 'folder-1', 'name' => 'SIPETA Backup']);
        $drive->shouldReceive('upload')->once()->andReturnUsing(fn (string $path, string $folder, string $filename, string $checksum): array => [
            'id' => 'drive-file-1',
            'name' => $filename,
            'parents' => [$folder],
            'appProperties' => ['sipeta_checksum' => $checksum],
        ]);

        $this->service->createToDrive($operator, $drive);

        $this->assertSame($operator->id, BackupLog::query()->first()->operator_id);
    }
}
