<?php

namespace App\Services;

use App\Exceptions\GoogleDriveException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleDriveClient
{
    public function __construct(private GoogleDriveOAuthService $oauth) {}

    /** @return array<string, mixed> */
    public function about(): array
    {
        return $this->request('GET', '/about', [
            'query' => ['fields' => 'user(emailAddress,displayName)'],
        ]);
    }

    /** @return array{id: string, name: string} */
    public function ensureBackupFolder(): array
    {
        $setting = app(SettingsService::class)->get();

        if (filled($setting->google_drive_folder_id)) {
            try {
                $folder = $this->request('GET', '/files/'.rawurlencode($setting->google_drive_folder_id), [
                    'query' => ['fields' => 'id,name,mimeType,trashed'],
                ]);

                if (($folder['mimeType'] ?? null) === 'application/vnd.google-apps.folder' && ! ($folder['trashed'] ?? false)) {
                    return ['id' => (string) $folder['id'], 'name' => (string) $folder['name']];
                }
            } catch (GoogleDriveException $e) {
                if ($e->httpStatus !== 404) {
                    throw $e;
                }
            }
        }

        $name = (string) config('services.google_drive.backup_folder_name', 'SIPETA Backup');
        $escapedName = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
        $list = $this->request('GET', '/files', [
            'query' => [
                'q' => "name = '{$escapedName}' and mimeType = 'application/vnd.google-apps.folder' and trashed = false",
                'spaces' => 'drive',
                'pageSize' => 10,
                'fields' => 'files(id,name)',
            ],
        ]);

        $existing = $list['files'][0] ?? null;
        if (is_array($existing) && filled($existing['id'] ?? null)) {
            app(SettingsService::class)->saveGoogleDriveConnection(
                $setting->google_drive_credentials ?? [],
                $setting->google_drive_account_email,
                (string) $existing['id'],
            );

            return ['id' => (string) $existing['id'], 'name' => (string) ($existing['name'] ?? $name)];
        }

        $folder = $this->request('POST', '/files', [
            'json' => [
                'name' => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
            ],
            'query' => ['fields' => 'id,name'],
        ]);

        if (blank($folder['id'] ?? null)) {
            throw new GoogleDriveException('Folder backup Google Drive tidak dapat dibuat.');
        }

        app(SettingsService::class)->saveGoogleDriveConnection(
            $setting->google_drive_credentials ?? [],
            $setting->google_drive_account_email,
            (string) $folder['id'],
        );

        return ['id' => (string) $folder['id'], 'name' => (string) ($folder['name'] ?? $name)];
    }

    /** @return array<string, mixed> */
    public function upload(string $path, string $folderId, string $filename, string $checksum): array
    {
        if (! is_file($path)) {
            throw new GoogleDriveException('Arsip sementara backup tidak ditemukan sebelum upload.');
        }

        $existing = $this->findBackup($folderId, $filename);
        if ($existing !== null) {
            if (($existing['appProperties']['sipeta_checksum'] ?? null) === $checksum) {
                return $this->metadata((string) $existing['id']);
            }

            throw new GoogleDriveException('Backup dengan nama yang sama sudah ada tetapi checksum berbeda.');
        }

        $size = filesize($path);
        if ($size === false) {
            throw new GoogleDriveException('Ukuran arsip backup tidak dapat dibaca.');
        }

        $response = $this->requestResponse('POST', (string) config('services.google_drive.upload_uri'), [
            'query' => ['uploadType' => 'resumable'],
            'headers' => [
                'X-Upload-Content-Type' => 'application/zip',
                'X-Upload-Content-Length' => (string) $size,
            ],
            'json' => [
                'name' => $filename,
                'parents' => [$folderId],
                'mimeType' => 'application/zip',
                'appProperties' => [
                    'sipeta_application' => 'SIPETA',
                    'sipeta_checksum' => $checksum,
                ],
            ],
        ]);

        $location = $response->header('Location');
        if (blank($location)) {
            throw new GoogleDriveException('Google Drive tidak mengembalikan sesi resumable upload.');
        }

        if (! is_readable($path)) {
            throw new GoogleDriveException('Arsip backup tidak dapat dibaca untuk upload.');
        }

        $uploaded = $this->uploadResumableContent($location, $path);

        if ($uploaded->failed() || blank($uploaded->json('id'))) {
            throw new GoogleDriveException('Upload backup ke Google Drive gagal.', $uploaded->status(), null, $uploaded->status());
        }

        return $this->metadata((string) $uploaded->json('id'));
    }

    private function uploadResumableContent(string $location, string $path): Response
    {
        $maxRetries = (int) config('services.google_drive.max_retries', 3);
        $refreshed = false;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new GoogleDriveException('Arsip backup tidak dapat dibaca untuk upload.');
            }

            try {
                $response = Http::withToken($this->oauth->accessToken($refreshed))
                    ->timeout((int) config('services.google_drive.upload_timeout', 600))
                    ->withHeaders(['Content-Type' => 'application/zip'])
                    ->send('PUT', $location, ['body' => $stream]);
            } catch (ConnectionException $e) {
                fclose($stream);
                if ($attempt + 1 >= $maxRetries) {
                    throw new GoogleDriveException('Koneksi Google Drive gagal saat upload.', 0, $e);
                }
                usleep(250000 * (2 ** $attempt));

                continue;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($response->status() === 401 && ! $refreshed) {
                $refreshed = true;

                continue;
            }

            if ($response->status() >= 500 && $attempt + 1 < $maxRetries) {
                usleep(250000 * (2 ** $attempt));

                continue;
            }

            if ($response->failed()) {
                throw new GoogleDriveException('Upload backup ke Google Drive gagal.', $response->status(), null, $response->status());
            }

            return $response;
        }

        throw new GoogleDriveException('Upload backup ke Google Drive gagal setelah beberapa percobaan.');
    }

    /** @return array<string, mixed> */
    public function metadata(string $fileId): array
    {
        return $this->request('GET', '/files/'.rawurlencode($fileId), [
            'query' => ['fields' => 'id,name,size,parents,mimeType,appProperties,trashed'],
        ]);
    }

    public function download(string $fileId, string $destination): void
    {
        $response = $this->requestResponse('GET', $this->url('/files/'.rawurlencode($fileId)), [
            'query' => ['alt' => 'media'],
        ]);

        if ($response->failed() || file_put_contents($destination, $response->body()) === false) {
            throw new GoogleDriveException('Backup dari Google Drive gagal diunduh.', $response->status(), null, $response->status());
        }
    }

    public function delete(string $fileId): void
    {
        try {
            $response = $this->requestResponse('DELETE', $this->url('/files/'.rawurlencode($fileId)));
        } catch (\Throwable $e) {
            Log::error('Google Drive delete request failed.', [
                'file_id' => $fileId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'previous_exception' => $e->getPrevious() ? get_class($e->getPrevious()) : null,
                'previous_message' => $e->getPrevious()?->getMessage(),
            ]);

            throw $e;
        }

        if ($response->failed()) {
            throw new GoogleDriveException('Backup Google Drive gagal dihapus.', $response->status(), null, $response->status());
        }
    }

    public function testConnection(): string
    {
        $about = $this->about();
        $email = $about['user']['emailAddress'] ?? null;
        if (blank($email)) {
            throw new GoogleDriveException('Identitas akun Google Drive tidak dapat dibaca.');
        }

        $folder = $this->ensureBackupFolder();

        return sprintf('%s (%s)', $email, $folder['name']);
    }

    /** @return array<int, array<string, mixed>> */
    public function listBackups(string $folderId): array
    {
        $escapedFolder = str_replace(['\\', "'"], ['\\\\', "\\'"], $folderId);
        $payload = $this->request('GET', '/files', [
            'query' => [
                'q' => "'{$escapedFolder}' in parents and mimeType = 'application/zip' and trashed = false",
                'spaces' => 'drive',
                'pageSize' => 100,
                'fields' => 'files(id,name,size,parents,mimeType,appProperties,createdTime)',
            ],
        ]);

        return $payload['files'] ?? [];
    }

    /** @return array<string, mixed> */
    private function findBackup(string $folderId, string $filename): ?array
    {
        $escapedFilename = str_replace(['\\', "'"], ['\\\\', "\\'"], $filename);
        $escapedFolder = str_replace(['\\', "'"], ['\\\\', "\\'"], $folderId);
        $payload = $this->request('GET', '/files', [
            'query' => [
                'q' => "name = '{$escapedFilename}' and '{$escapedFolder}' in parents and trashed = false",
                'spaces' => 'drive',
                'pageSize' => 10,
                'fields' => 'files(id,name,size,parents,mimeType,appProperties)',
            ],
        ]);

        return $payload['files'][0] ?? null;
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $uri, array $options = []): array
    {
        $response = $this->requestResponse($method, $this->url($uri), $options);
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new GoogleDriveException('Respons Google Drive tidak valid.', $response->status(), null, $response->status());
        }

        return $payload;
    }

    private function requestResponse(string $method, string $uri, array $options = []): Response
    {
        $maxRetries = (int) config('services.google_drive.max_retries', 3);
        $refreshed = false;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $token = $this->oauth->accessToken($refreshed);
                $request = Http::withToken($token)->timeout(
                    (int) ($options['timeout'] ?? config('services.google_drive.timeout', 120))
                )->withOptions(['verify' => base_path('resources/php/cacert.pem')]);
                unset($options['timeout'], $options['retry']);
                $response = $request->send($method, $uri, $options);
            } catch (ConnectionException $e) {
                if ($attempt + 1 >= $maxRetries) {
                    throw new GoogleDriveException('Koneksi Google Drive gagal.', 0, $e);
                }

                usleep(250000 * (2 ** $attempt));

                continue;
            }

            if ($response->status() === 401 && ! $refreshed) {
                $refreshed = true;

                continue;
            }

            if ($response->status() >= 500 && $attempt + 1 < $maxRetries) {
                usleep(250000 * (2 ** $attempt));

                continue;
            }

            if ($response->failed()) {
                $message = match ($response->status()) {
                    401 => 'Token Google perlu diotorisasi ulang.',
                    403 => 'Google Drive menolak akses atau kuota telah tercapai.',
                    404 => 'File atau folder Google Drive tidak ditemukan.',
                    default => 'Operasi Google Drive gagal.',
                };
                throw new GoogleDriveException($message, $response->status(), null, $response->status());
            }

            return $response;
        }

        throw new GoogleDriveException('Operasi Google Drive gagal setelah beberapa percobaan.');
    }

    private function url(string $uri): string
    {
        return str_starts_with($uri, 'http')
            ? $uri
            : rtrim((string) config('services.google_drive.drive_uri'), '/').'/'.ltrim($uri, '/');
    }
}
