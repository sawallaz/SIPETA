<?php

namespace Tests\Feature\Phase6;

use App\Exceptions\RestoreException;
use App\Models\Setting;
use App\Services\GoogleDriveClient;
use App\Services\RestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeDatabaseImporter;
use Tests\TestCase;
use ZipArchive;

class RestoreServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(RestoreService::PHOTO_DISK);
        Storage::fake('local');
    }

    public function test_restore_requires_explicit_confirmation(): void
    {
        $drive = $this->mock(GoogleDriveClient::class);
        $drive->shouldNotReceive('download');

        $result = (new RestoreService(new FakeDatabaseImporter(false), $drive))
            ->restoreFromDrive('drive-file-1');

        $this->assertTrue($result->isConfirmationRequired());
        $this->assertSame('drive-file-1', $result->filename);
    }

    public function test_corrupt_archive_is_rejected_and_download_is_cleaned(): void
    {
        $sourcePath = tempnam(sys_get_temp_dir(), 'sipeta_corrupt_');
        file_put_contents($sourcePath, 'NOT A ZIP');
        $downloadedPath = null;
        $drive = $this->downloadMock($sourcePath, $downloadedPath);

        try {
            $this->expectException(RestoreException::class);
            (new RestoreService(new FakeDatabaseImporter(false), $drive))->restoreFromDrive('drive-file-1', null, true);
        } finally {
            $this->assertNotNull($downloadedPath);
            $this->assertFileDoesNotExist((string) $downloadedPath);
            @unlink($sourcePath);
        }
    }

    public function test_archive_missing_required_database_is_rejected_before_import(): void
    {
        $sourcePath = $this->createZipFile(['settings.json' => '[]']);
        $importer = new FakeDatabaseImporter;
        $drive = $this->downloadMock($sourcePath, $unused);

        try {
            $this->expectExceptionMessage('database.sql tidak ditemukan');
            (new RestoreService($importer, $drive))->restoreFromDrive('drive-file-1', null, true);
        } finally {
            @unlink($sourcePath);
        }

        $this->assertSame([], $importer->applied);
    }

    public function test_checksum_mismatch_blocks_restore(): void
    {
        $sourcePath = $this->createZipFile([
            'database.sql' => 'SQL',
            'settings.json' => '[]',
            'manifest.json' => json_encode([
                'application_identifier' => 'SIPETA',
                'file_count' => 2,
                'total_size' => 3,
                'checksum' => str_repeat('0', 64),
            ]),
        ]);
        $importer = new FakeDatabaseImporter;
        $drive = $this->downloadMock($sourcePath, $unused);

        try {
            $this->expectExceptionMessage('Checksum backup tidak valid.');
            (new RestoreService($importer, $drive))->restoreFromDrive('drive-file-1', null, true);
        } finally {
            @unlink($sourcePath);
        }

        $this->assertSame([], $importer->applied);
    }

    public function test_google_drive_checksum_mismatch_blocks_downloaded_archive(): void
    {
        $sourcePath = $this->createZipFile([
            'database.sql' => 'SQL',
            'settings.json' => '[]',
        ]);
        $importer = new FakeDatabaseImporter;
        $downloadedPath = null;
        $drive = $this->mock(GoogleDriveClient::class);
        $drive->shouldReceive('metadata')->once()->andReturn([
            'id' => 'drive-file-1',
            'appProperties' => ['sipeta_checksum' => str_repeat('0', 64)],
        ]);
        $drive->shouldReceive('download')->once()->andReturnUsing(function (string $fileId, string $destination) use ($sourcePath, &$downloadedPath): void {
            $downloadedPath = $destination;
            copy($sourcePath, $destination);
        });

        try {
            $this->expectExceptionMessage('Checksum backup tidak valid.');
            (new RestoreService($importer, $drive))->restoreFromDrive('drive-file-1', null, true);
        } finally {
            $this->assertNotNull($downloadedPath);
            $this->assertFileDoesNotExist((string) $downloadedPath);
            @unlink($sourcePath);
        }

        $this->assertSame([], $importer->applied);
    }

    public function test_path_traversal_is_blocked_before_import(): void
    {
        $sourcePath = $this->createZipFile([
            'database.sql' => 'SQL',
            'settings.json' => '[]',
            '../outside.txt' => 'DO_NOT_WRITE',
        ]);
        $importer = new FakeDatabaseImporter;
        $drive = $this->downloadMock($sourcePath, $unused);

        try {
            $this->expectExceptionMessage('path file yang tidak aman');
            (new RestoreService($importer, $drive))->restoreFromDrive('drive-file-1', null, true);
        } finally {
            @unlink($sourcePath);
        }

        $this->assertSame([], $importer->applied);
        $this->assertFalse(Storage::disk('local')->exists('outside.txt'));
    }

    public function test_valid_drive_archive_restores_and_cleans_download(): void
    {
        $sourcePath = $this->createZipFile([
            'database.sql' => 'INSERT INTO example (id) VALUES (1);',
            'settings.json' => json_encode([
                'kelurahan_name' => 'Kelurahan Pulih',
                'kecamatan_name' => 'Kec. Pulih',
                'kabupaten_name' => 'Kab. Pulih',
                'province_name' => 'Prov. Pulih',
            ]),
            'kk/pulih.jpg' => 'PULIH_PHOTO',
        ]);
        $importer = new FakeDatabaseImporter;
        $downloadedPath = null;
        $drive = $this->downloadMock($sourcePath, $downloadedPath);

        try {
            $result = (new RestoreService($importer, $drive))->restoreFromDrive('drive-file-1', null, true);
        } finally {
            @unlink($sourcePath);
        }

        $this->assertTrue($result->isRestored());
        $this->assertSame(['INSERT INTO example (id) VALUES (1);'], $importer->applied);
        $this->assertSame('Kelurahan Pulih', Setting::query()->first()?->kelurahan_name);
        $this->assertTrue(Storage::disk(RestoreService::PHOTO_DISK)->exists('kk/pulih.jpg'));
        $this->assertSame('PULIH_PHOTO', Storage::disk(RestoreService::PHOTO_DISK)->get('kk/pulih.jpg'));
        $this->assertFileDoesNotExist((string) $downloadedPath);
    }

    private function downloadMock(string $sourcePath, ?string &$downloadedPath): GoogleDriveClient
    {
        $drive = $this->mock(GoogleDriveClient::class);
        $drive->shouldReceive('metadata')->once()->andReturn([
            'id' => 'drive-file-1',
            'appProperties' => ['sipeta_checksum' => hash_file('sha256', $sourcePath)],
        ]);
        $drive->shouldReceive('download')->once()->andReturnUsing(function (string $fileId, string $destination) use ($sourcePath, &$downloadedPath): void {
            $downloadedPath = $destination;
            copy($sourcePath, $destination);
        });

        return $drive;
    }

    /** @param array<string, string> $entries */
    private function createZipFile(array $entries): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sipeta_restore_source_');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();

        return $tmp;
    }
}
