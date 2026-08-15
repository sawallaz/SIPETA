<?php

namespace App\Filament\Pages;

use App\Enums\BackupStatus;
use App\Exceptions\BackupException;
use App\Exceptions\GoogleDriveException;
use App\Exceptions\RestoreException;
use App\Models\BackupLog;
use App\Services\BackupService;
use App\Services\GoogleDriveClient;
use App\Services\RestoreService;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

class Backup extends Page
{
    protected string $view = 'filament.pages.backup';

    protected static ?string $navigationLabel = 'Backup';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 80;

    public ?string $driveRestoreCandidate = null;

    public ?string $driveRestoreFilename = null;

    public ?string $driveDeleteCandidate = null;

    public ?string $driveDeleteFilename = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function getTitle(): string
    {
        return 'Backup & Restore';
    }

    public function getViewData(): array
    {
        return [
            'driveBackups' => BackupLog::query()
                ->whereNotNull('drive_file_id')
                ->where('backup_status', BackupStatus::SUCCESS)
                ->latest('started_at')
                ->get(),
            'googleDrive' => app(SettingsService::class)->get(),
        ];
    }

    public function mount(): void
    {
        if (session()->has('google_drive_message')) {
            Notification::make()
                ->title((string) session()->pull('google_drive_message'))
                ->success()
                ->send();
        }

        if (session()->has('google_drive_error')) {
            Notification::make()
                ->title('Google Drive gagal')
                ->body((string) session()->pull('google_drive_error'))
                ->danger()
                ->send();
        }
    }

    public function createGoogleDriveBackup(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        try {
            app(BackupService::class)->createToDrive(
                auth()->user(),
                app(GoogleDriveClient::class),
            );

            Notification::make()
                ->title('Backup berhasil')
                ->body('Backup berhasil diunggah ke Google Drive.')
                ->success()
                ->send();
        } catch (GoogleDriveException $e) {
            Notification::make()
                ->title('Backup gagal')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } catch (BackupException $e) {
            Notification::make()
                ->title('Backup gagal')
                ->body($e->getMessage() ?: 'Backup tidak dapat dibuat atau diunggah.')
                ->danger()
                ->send();
        } catch (Throwable) {
            Notification::make()
                ->title('Backup gagal')
                ->body('Backup tidak dapat dibuat atau diunggah.')
                ->danger()
                ->send();
        }
    }

    public function testGoogleDriveConnection(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        try {
            $identity = app(GoogleDriveClient::class)->testConnection();
            Notification::make()
                ->title('Koneksi Google Drive aktif')
                ->body('Terhubung sebagai '.$identity.'.')
                ->success()
                ->send();
        } catch (GoogleDriveException $e) {
            Notification::make()
                ->title('Uji koneksi gagal')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function disconnectGoogleDrive(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        app(SettingsService::class)->disconnectGoogleDrive();
        logger()->info('Google Drive disconnected.', ['operator_id' => auth()->id()]);

        Notification::make()
            ->title('Google Drive diputuskan')
            ->body('Akun Google Drive berhasil diputuskan.')
            ->success()
            ->send();
    }

    public function requestDriveRestore(string $fileId, string $filename): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        if (! BackupLog::query()
            ->where('drive_file_id', $fileId)
            ->where('backup_status', BackupStatus::SUCCESS)
            ->exists()) {
            Notification::make()
                ->title('Backup tidak ditemukan')
                ->body('Riwayat backup Google Drive tersebut tidak tersedia.')
                ->danger()
                ->send();

            return;
        }

        $this->driveRestoreCandidate = $fileId;
        $this->driveRestoreFilename = basename($filename);
    }

    public function cancelDriveRestore(): void
    {
        $this->driveRestoreCandidate = null;
        $this->driveRestoreFilename = null;
    }

    public function confirmDriveRestore(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        if ($this->driveRestoreCandidate === null) {
            return;
        }

        if (! BackupLog::query()
            ->where('drive_file_id', $this->driveRestoreCandidate)
            ->where('backup_status', BackupStatus::SUCCESS)
            ->exists()) {
            $this->cancelDriveRestore();

            Notification::make()
                ->title('Backup tidak ditemukan')
                ->body('Riwayat backup Google Drive tersebut tidak tersedia.')
                ->danger()
                ->send();

            return;
        }

        try {
            $result = app(RestoreService::class)->restoreFromDrive(
                $this->driveRestoreCandidate,
                auth()->user(),
                true,
            );

            if ($result->isRestored()) {
                Notification::make()
                    ->title('Pemulihan selesai')
                    ->body('Data berhasil dipulihkan dari Google Drive. Silakan restart aplikasi.')
                    ->warning()
                    ->send();
                $this->cancelDriveRestore();
            }
        } catch (RestoreException $e) {
            Notification::make()
                ->title('Pemulihan gagal')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } catch (Throwable $e) {
            logger()->error('Google Drive restore failed unexpectedly.', [
                'file_id' => $this->driveRestoreCandidate,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Pemulihan gagal')
                ->body('Backup Google Drive tidak dapat dipulihkan.')
                ->danger()
                ->send();
        }
    }

    public function requestDriveDelete(string $fileId, string $filename): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $exists = BackupLog::query()
            ->where('drive_file_id', $fileId)
            ->where('backup_status', BackupStatus::SUCCESS)
            ->exists();

        if (! $exists) {
            Notification::make()
                ->title('Backup tidak ditemukan')
                ->body('Riwayat backup Google Drive tersebut tidak tersedia.')
                ->danger()
                ->send();

            return;
        }

        $this->driveDeleteCandidate = $fileId;
        $this->driveDeleteFilename = basename($filename);
    }

    public function cancelDriveDelete(): void
    {
        $this->driveDeleteCandidate = null;
        $this->driveDeleteFilename = null;
    }

    public function confirmDriveDelete(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $fileId = $this->driveDeleteCandidate;
        if ($fileId === null) {
            return;
        }

        $log = BackupLog::query()
            ->where('drive_file_id', $fileId)
            ->where('backup_status', BackupStatus::SUCCESS)
            ->first();

        if ($log === null) {
            $this->cancelDriveDelete();

            Notification::make()
                ->title('Backup tidak ditemukan')
                ->body('Riwayat backup Google Drive tersebut tidak tersedia.')
                ->danger()
                ->send();

            return;
        }

        try {
            app(GoogleDriveClient::class)->delete($fileId);
            $log->delete();
            $filename = $log->filename;
            $this->cancelDriveDelete();

            Notification::make()
                ->title('Backup Google Drive berhasil dihapus')
                ->body($filename.' telah dihapus dari Google Drive dan histori.')
                ->success()
                ->send();
        } catch (GoogleDriveException $e) {
            Notification::make()
                ->title('Gagal menghapus backup Google Drive')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } catch (Throwable $e) {
            logger()->error('Google Drive delete failed unexpectedly.', [
                'file_id' => $fileId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Gagal menghapus backup Google Drive')
                ->body('File Google Drive tidak dihapus dan histori tetap dipertahankan.')
                ->danger()
                ->send();
        }
    }
}
