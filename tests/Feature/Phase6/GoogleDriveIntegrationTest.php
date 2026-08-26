<?php

namespace Tests\Feature\Phase6;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\UserRole;
use App\Exceptions\GoogleDriveException;
use App\Filament\Pages\Backup;
use App\Models\BackupLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\BackupService;
use App\Services\GoogleDriveClient;
use App\Services\GoogleDriveOAuthService;
use App\Services\RestoreService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakeDatabaseDumper;
use Tests\Support\FakeDatabaseImporter;
use Tests\TestCase;
use ZipArchive;

class GoogleDriveIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenUri = 'https://oauth2.googleapis.com/token';

    private string $driveUri = 'https://www.googleapis.com/drive/v3';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google_drive.client_id' => 'client-id',
            'services.google_drive.client_secret' => 'client-secret',
            'services.google_drive.redirect_uri' => 'http://localhost/admin/backup/google/callback',
        ]);
        Storage::fake('local');
        Storage::fake(RestoreService::PHOTO_DISK);
    }

    public function test_oauth_authorization_url_uses_drive_file_scope_and_state(): void
    {
        $url = app(GoogleDriveOAuthService::class)->authorizationUrl('state-123');

        $this->assertStringContainsString('scope='.rawurlencode(GoogleDriveOAuthService::SCOPE), $url);
        $this->assertStringContainsString('state=state-123', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('redirect_uri='.rawurlencode('http://localhost/admin/backup/google/callback'), $url);
    }

    public function test_oauth_authorization_url_supports_custom_redirect_uri(): void
    {
        $url = app(GoogleDriveOAuthService::class)->authorizationUrl('state-456', 'http://localhost:8100/admin/backup/google/callback');

        $this->assertStringContainsString('redirect_uri='.rawurlencode('http://localhost:8100/admin/backup/google/callback'), $url);
    }

    public function test_oauth_connect_saves_state_and_redirect_uri_in_session(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
        $response = $this->actingAs($admin)->get(route('google-drive.connect'));

        $response->assertRedirect();
        // State is stored in session (same-host fallback)
        $this->assertNotNull(session('google_drive_oauth_state'));
        // State is also stored in Cache (cross-host primary) — key format: google_oauth_state_{state}
        $stateFromSession = session('google_drive_oauth_state');
        $this->assertNotNull(\Illuminate\Support\Facades\Cache::get('google_oauth_state_' . $stateFromSession));
    }

    public function test_oauth_state_is_required_and_operator_is_blocked(): void
    {
        $operator = User::factory()->create(['role' => UserRole::OPERATOR]);
        $this->actingAs($operator)
            ->get(route('google-drive.connect'))
            ->assertForbidden();

        $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
        $this->actingAs($admin)
            ->get(route('google-drive.callback', ['state' => 'wrong', 'code' => 'code']))
            ->assertStatus(419);
    }

    public function test_oauth_code_persists_encrypted_credentials_without_plaintext_token(): void
    {
        Http::fake([
            $this->tokenUri => Http::response([
                'access_token' => 'access-secret',
                'refresh_token' => 'refresh-secret',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
        ]);

        $oauth = app(GoogleDriveOAuthService::class);
        $credentials = $oauth->exchangeCode('one-time-code');
        app(SettingsService::class)->saveGoogleDriveConnection($credentials, 'admin@gmail.com', 'folder-1');

        $raw = (string) DB::table('settings')->value('google_drive_credentials');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('access-secret', $raw);
        $this->assertStringNotContainsString('refresh-secret', $raw);
        $this->assertSame('admin@gmail.com', Setting::query()->first()->google_drive_account_email);
        $this->assertSame('folder-1', Setting::query()->first()->google_drive_folder_id);
    }

    public function test_expired_access_token_is_refreshed_and_persisted(): void
    {
        app(SettingsService::class)->saveGoogleDriveConnection([
            'access_token' => 'expired',
            'refresh_token' => 'refresh-secret',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ], 'admin@gmail.com', 'folder-1');
        Http::fake([$this->tokenUri => Http::response(['access_token' => 'fresh', 'expires_in' => 3600])]);

        $this->assertSame('fresh', app(GoogleDriveOAuthService::class)->accessToken());
        $raw = (string) DB::table('settings')->value('google_drive_credentials');
        $this->assertStringNotContainsString('fresh', $raw);
    }

    public function test_folder_is_created_once_and_uses_existing_folder_id(): void
    {
        $this->saveCredentials();
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/files')) {
                return Http::response(['files' => []]);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/files')) {
                return Http::response(['id' => 'folder-created', 'name' => 'SIPETA Backup']);
            }

            return Http::response([], 500);
        });

        $folder = app(GoogleDriveClient::class)->ensureBackupFolder();
        $this->assertSame('folder-created', $folder['id']);

        Http::fake([
            $this->driveUri.'/files/folder-created*' => Http::response([
                'id' => 'folder-created',
                'name' => 'SIPETA Backup',
                'mimeType' => 'application/vnd.google-apps.folder',
                'trashed' => false,
            ]),
        ]);
        $this->assertSame('folder-created', app(GoogleDriveClient::class)->ensureBackupFolder()['id']);
    }

    public function test_resumable_upload_verifies_metadata_and_reuses_same_checksum(): void
    {
        $this->saveCredentials();
        $path = tempnam(sys_get_temp_dir(), 'sipeta_upload_');
        file_put_contents($path, 'ZIP_BYTES');
        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = [$request->method(), $request->url()];
            if ($request->method() === 'GET' && str_contains($request->url(), '/files?')) {
                return Http::response(['files' => []]);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), 'upload/drive')) {
                return Http::response([], 200, ['Location' => 'https://upload.example/session/1']);
            }
            if ($request->method() === 'PUT') {
                return Http::response(['id' => 'drive-file-1']);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/files/drive-file-1')) {
                return Http::response([
                    'id' => 'drive-file-1',
                    'name' => 'backup.zip',
                    'parents' => ['folder-1'],
                    'appProperties' => ['sipeta_checksum' => 'checksum-1'],
                ]);
            }

            return Http::response([], 500);
        });

        try {
            $metadata = app(GoogleDriveClient::class)->upload($path, 'folder-1', 'backup.zip', 'checksum-1');
            $this->assertSame('drive-file-1', $metadata['id']);
            $this->assertContains(['PUT', 'https://upload.example/session/1'], $calls);
        } finally {
            @unlink($path);
        }
    }

    public function test_google_api_401_refreshes_once(): void
    {
        $this->saveCredentials();
        $aboutCalls = 0;
        Http::fake(function (Request $request) use (&$aboutCalls) {
            if ($request->url() === $this->tokenUri) {
                return Http::response(['access_token' => 'fresh', 'expires_in' => 3600]);
            }
            if (str_contains($request->url(), '/about')) {
                $aboutCalls++;

                return $aboutCalls === 1
                    ? Http::response([], 401)
                    : Http::response(['user' => ['emailAddress' => 'admin@gmail.com']]);
            }

            return Http::response([], 403);
        });

        $this->assertSame('admin@gmail.com', app(GoogleDriveClient::class)->about()['user']['emailAddress']);
        $this->assertSame(2, $aboutCalls);

    }

    public function test_google_api_403_is_reported(): void
    {
        $this->saveCredentials();
        Http::fake(function (Request $request) {
            return Http::response([], 403);
        });

        try {
            app(GoogleDriveClient::class)->about();
            $this->fail('Expected GoogleDriveException for a 403 response.');
        } catch (GoogleDriveException $e) {
            $this->assertSame(403, $e->httpStatus);
        }
    }

    public function test_google_api_5xx_is_retried_with_backoff(): void
    {
        $this->saveCredentials();
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response([], 503)
                : Http::response(['user' => ['emailAddress' => 'admin@gmail.com']]);
        });

        $this->assertSame('admin@gmail.com', app(GoogleDriveClient::class)->about()['user']['emailAddress']);
        $this->assertSame(2, $calls);
    }

    public function test_backup_creates_manifest_checksum_and_drive_history(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
        $this->saveCredentials();
        $drive = $this->mock(GoogleDriveClient::class);
        $drive->shouldReceive('ensureBackupFolder')->once()->andReturn(['id' => 'folder-1', 'name' => 'SIPETA Backup']);
        $uploadedPath = null;
        $drive->shouldReceive('upload')->once()->andReturnUsing(function (string $path, string $folder, string $filename, string $checksum) use (&$uploadedPath): array {
            $uploadedPath = $path;
            $this->assertFileExists($path);
            $this->assertSame('folder-1', $folder);
            $this->assertNotSame('', $checksum);

            return [
                'id' => 'drive-file-1',
                'name' => $filename,
                'parents' => ['folder-1'],
                'appProperties' => ['sipeta_checksum' => $checksum],
            ];
        });

        $result = (new BackupService(new FakeDatabaseDumper('DUMMY_SQL')))->createToDrive($admin, $drive);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('drive-file-1', $result->driveFileId);
        $this->assertSame(BackupStatus::SUCCESS, BackupLog::query()->first()->backup_status);
        $this->assertNotNull($uploadedPath);
        $this->assertFileDoesNotExist($uploadedPath);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_drive_upload_failure_is_logged_failed_without_false_success(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
        $drive = $this->mock(GoogleDriveClient::class);
        $drive->shouldReceive('ensureBackupFolder')->once()->andReturn(['id' => 'folder-1', 'name' => 'SIPETA Backup']);
        $drive->shouldReceive('upload')->once()->andThrow(new GoogleDriveException('Google Drive menolak akses.', 403));

        $this->expectExceptionMessage('Google Drive menolak akses.');
        try {
            (new BackupService(new FakeDatabaseDumper('DUMMY_SQL')))->createToDrive($admin, $drive);
        } finally {
            $this->assertSame(BackupStatus::FAILED, BackupLog::query()->first()->backup_status);
        }
    }

    public function test_restore_blocks_manifest_checksum_mismatch(): void
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
        $drive = $this->mock(GoogleDriveClient::class);
        $drive->shouldReceive('metadata')->once()->andReturn([
            'id' => 'drive-file-tampered',
            'appProperties' => ['sipeta_checksum' => hash_file('sha256', $sourcePath)],
        ]);
        $drive->shouldReceive('download')->once()->andReturnUsing(function (string $fileId, string $destination) use ($sourcePath): void {
            copy($sourcePath, $destination);
        });

        $this->expectExceptionMessage('Checksum backup tidak valid.');
        try {
            (new RestoreService($importer, $drive))->restoreFromDrive('drive-file-tampered', null, true);
        } finally {
            $this->assertSame([], $importer->applied);
            @unlink($sourcePath);
        }
    }

    public function test_restore_from_drive_downloads_and_applies_valid_archive(): void
    {
        $sourcePath = $this->createZipFile([
            'database.sql' => 'RESTORE_SQL',
            'settings.json' => '[]',
        ]);
        $importer = new FakeDatabaseImporter;
        $drive = $this->mock(GoogleDriveClient::class);
        $downloadedPath = null;
        $drive->shouldReceive('metadata')->once()->andReturn([
            'id' => 'drive-file-1',
            'appProperties' => ['sipeta_checksum' => hash_file('sha256', $sourcePath)],
        ]);
        $drive->shouldReceive('download')->once()->andReturnUsing(function (string $fileId, string $destination) use ($sourcePath, &$downloadedPath): void {
            $this->assertSame('drive-file-1', $fileId);
            $downloadedPath = $destination;
            copy($sourcePath, $destination);
        });

        $result = (new RestoreService($importer, $drive))->restoreFromDrive('drive-file-1', null, true);

        $this->assertTrue($result->isRestored());
        $this->assertSame(['RESTORE_SQL'], $importer->applied);
        $this->assertNotNull($downloadedPath);
        $this->assertFileDoesNotExist($downloadedPath);
        @unlink($sourcePath);
    }

    public function test_drive_delete_removes_file_before_history_entry(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
        BackupLog::create([
            'filename' => 'backup_2026-08-15_120000.zip',
            'drive_file_id' => 'drive-file-delete',
            'drive_folder_id' => 'folder-1',
            'backup_status' => BackupStatus::SUCCESS,
            'backup_size' => 100,
            'checksum' => str_repeat('a', 64),
            'operator_id' => $admin->id,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $drive = $this->mock(GoogleDriveClient::class);
        $drive->shouldReceive('delete')->once()->with('drive-file-delete');
        $this->app->instance(GoogleDriveClient::class, $drive);

        $this->actingAs($admin);
        Livewire::test(Backup::class)
            ->call('requestDriveDelete', 'drive-file-delete', 'backup_2026-08-15_120000.zip')
            ->assertSet('driveDeleteCandidate', 'drive-file-delete')
            ->call('confirmDriveDelete')
            ->assertNotified('Backup Google Drive berhasil dihapus');

        $this->assertDatabaseMissing('backup_logs', ['drive_file_id' => 'drive-file-delete']);
    }

    public function test_disconnect_clears_google_credentials_and_folder(): void
    {
        $this->saveCredentials();
        app(SettingsService::class)->disconnectGoogleDrive();

        $setting = Setting::query()->first();
        $this->assertNull($setting->google_drive_account_email);
        $this->assertNull($setting->google_drive_folder_id);
        $this->assertNull($setting->google_drive_credentials);
        $this->assertNull($setting->google_drive_connected_at);
    }

    public function test_operator_cannot_open_backup_page_or_manage_google_drive(): void
    {
        $operator = User::factory()->create(['role' => UserRole::OPERATOR]);
        $this->actingAs($operator)
            ->get(Backup::getUrl())
            ->assertForbidden();
        $this->get(route('google-drive.connect'))->assertForbidden();
        $this->assertFalse(Backup::canAccess());

        foreach ([
            'createGoogleDriveBackup' => [],
            'testGoogleDriveConnection' => [],
            'disconnectGoogleDrive' => [],
            'requestDriveRestore' => ['drive-file-1', 'backup.zip'],
            'confirmDriveRestore' => [],
            'requestDriveDelete' => ['drive-file-1', 'backup.zip'],
            'confirmDriveDelete' => [],
        ] as $method => $arguments) {
            try {
                $component = new Backup;
                $component->{$method}(...$arguments);
                $this->fail("Expected operator call {$method} to be forbidden.");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), "Unexpected status for {$method}.");
            }
        }
    }

    public function test_sync_from_drive_is_idempotent_and_reflects_remote_files(): void
    {
        $this->saveCredentials();

        Http::fake([
            $this->driveUri.'/files*' => Http::response([
                'files' => [
                    [
                        'id' => 'drive-file-remote-1',
                        'name' => 'backup_2026-08-18_010000.zip',
                        'size' => 1024000,
                        'createdTime' => '2026-08-18T01:00:00Z',
                        'appProperties' => [
                            'sipeta_checksum' => 'sha256:remote1',
                        ],
                    ],
                    [
                        'id' => 'drive-file-remote-2',
                        'name' => 'backup_2026-08-18_020000.zip',
                        'size' => 2048000,
                        'createdTime' => '2026-08-18T02:00:00Z',
                        'appProperties' => [
                            'sipeta_checksum' => 'sha256:remote2',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $driveClient = app(GoogleDriveClient::class);
        $backupService = app(BackupService::class);

        // First sync
        $backupService->syncFromDrive($driveClient);

        $this->assertSame(2, BackupLog::count());
        $this->assertDatabaseHas('backup_logs', ['drive_file_id' => 'drive-file-remote-1', 'backup_status' => BackupStatus::SUCCESS->value]);
        $this->assertDatabaseHas('backup_logs', ['drive_file_id' => 'drive-file-remote-2', 'backup_status' => BackupStatus::SUCCESS->value]);

        // Second sync (idempotent)
        $backupService->syncFromDrive($driveClient);
        $this->assertSame(2, BackupLog::count());
    }

    public function test_view_data_is_empty_when_disconnected_even_if_stale_logs_exist(): void
    {
        // Create stale local log
        BackupLog::create([
            'filename' => 'stale_backup.zip',
            'backup_type' => BackupType::MANUAL,
            'backup_status' => BackupStatus::SUCCESS,
            'backup_size' => 1024,
            'checksum' => 'sha256:stale',
            'drive_file_id' => 'stale-id',
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $admin = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
        $this->actingAs($admin);

        // Ensure Setting has no google drive connection
        $setting = Setting::firstOrCreate(['id' => 1], [
            'kelurahan_name' => 'Kelurahan Tanete',
            'kecamatan_name' => 'Bulukumpa',
            'kabupaten_name' => 'Bulukumba',
            'province_name' => 'Sulawesi Selatan',
        ]);
        $setting->update([
            'google_drive_account_email' => null,
            'google_drive_credentials' => null,
            'google_drive_folder_id' => null,
        ]);

        $component = new Backup;
        $viewData = $component->getViewData();

        $this->assertCount(0, $viewData['driveBackups']);
    }

    public function test_sync_cleans_deleted_remote_files(): void
    {
        $this->saveCredentials();

        $syncCallCount = 0;
        Http::fake(function (Request $request) use (&$syncCallCount) {
            if (str_contains($request->url(), '/files/folder-1')) {
                return Http::response([
                    'id' => 'folder-1',
                    'name' => 'SIPETA Backup',
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'trashed' => false,
                ], 200);
            }

            if (str_contains($request->url(), '/files')) {
                $syncCallCount++;
                if ($syncCallCount === 1) {
                    return Http::response([
                        'files' => [
                            [
                                'id' => 'file-1',
                                'name' => 'backup_1.zip',
                                'size' => 1024,
                                'createdTime' => '2026-08-18T01:00:00Z',
                            ],
                            [
                                'id' => 'file-2',
                                'name' => 'backup_2.zip',
                                'size' => 2048,
                                'createdTime' => '2026-08-18T02:00:00Z',
                            ],
                        ],
                    ], 200);
                }

                if ($syncCallCount === 2) {
                    return Http::response([
                        'files' => [
                            [
                                'id' => 'file-1',
                                'name' => 'backup_1.zip',
                                'size' => 1024,
                                'createdTime' => '2026-08-18T01:00:00Z',
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'files' => [],
                ], 200);
            }

            return Http::response([], 200);
        });

        $driveClient = app(GoogleDriveClient::class);
        $backupService = app(BackupService::class);

        // First sync -> 2 files
        $backupService->syncFromDrive($driveClient);
        $this->assertSame(2, BackupLog::count());

        // Second sync -> 1 file
        $backupService->syncFromDrive($driveClient);
        $this->assertSame(1, BackupLog::count());
        $this->assertDatabaseHas('backup_logs', ['drive_file_id' => 'file-1']);
        $this->assertDatabaseMissing('backup_logs', ['drive_file_id' => 'file-2']);

        // Third sync -> 0 files
        $backupService->syncFromDrive($driveClient);
        $this->assertSame(0, BackupLog::count());
    }

    private function saveCredentials(): void
    {
        app(SettingsService::class)->saveGoogleDriveConnection([
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour()->toIso8601String(),
        ], 'admin@gmail.com', 'folder-1');
    }

    private function createZipFile(array $entries): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sipeta_drive_source_');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();

        return $tmp;
    }
}
