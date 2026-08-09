<?php

namespace App\Filament\Pages;

use App\Exceptions\RestoreException;
use App\Services\BackupService;
use App\Services\RestoreService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

class Backup extends Page
{
    protected string $view = 'filament.pages.backup';

    protected static ?string $navigationLabel = 'Backup';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 80;

    /**
     * File backup yang sedang dipilih untuk restore.
     */
    public ?string $restoreCandidate = null;

    /**
     * File backup yang sedang dipilih untuk dihapus.
     */
    public ?string $deleteCandidate = null;

    public function getTitle(): string
    {
        return 'Backup & Restore';
    }

    public function getViewData(): array
    {
        return [
            'backups' => $this->backups(),
        ];
    }

    /**
     * Membuat backup baru.
     */
    public function createBackup(): void
    {
        try {
            $result = app(BackupService::class)->create(auth()->user());

            if ($result->isDuplicate()) {
                Notification::make()
                    ->title('Backup sudah ada')
                    ->body(
                        'Arsip '.$result->filename.
                        ' sudah pernah dibuat. Buat ulang tidak diperlukan.'
                    )
                    ->warning()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Backup berhasil')
                ->body(
                    'Arsip '.$result->filename.
                    ' berhasil disimpan.'
                )
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Backup gagal')
                ->body(
                    $e->getMessage() ?: 'Backup tidak dapat dibuat.'
                )
                ->danger()
                ->send();
        }
    }

    /**
     * Tahap 1 restore:
     * memilih file backup.
     */
    public function requestRestore(string $filename): void
    {
        $filename = basename($filename);

        if (! $this->isValidBackupFilename($filename)) {
            Notification::make()
                ->title('Arsip tidak valid')
                ->body('Hanya file backup ZIP SIPETA yang dapat dipulihkan.')
                ->danger()
                ->send();

            return;
        }

        $disk = Storage::disk(RestoreService::DISK);

        if (! $disk->exists($filename)) {
            Notification::make()
                ->title('Backup tidak ditemukan')
                ->body('File backup tersebut sudah tidak tersedia.')
                ->danger()
                ->send();

            return;
        }

        $this->restoreCandidate = $filename;
        $this->deleteCandidate = null;
    }

    /**
     * Membatalkan restore.
     */
    public function cancelRestore(): void
    {
        $this->restoreCandidate = null;
    }

    /**
     * Tahap 2 restore:
     * benar-benar menjalankan restore setelah konfirmasi.
     */
    public function confirmRestore(): void
    {
        $filename = $this->restoreCandidate;

        if ($filename === null) {
            return;
        }

        try {
            $result = app(RestoreService::class)->restore(
                $filename,
                auth()->user(),
                true,
            );

            if (! $result->isRestored()) {
                Notification::make()
                    ->title('Pemulihan tidak diproses')
                    ->body('Pemulihan memerlukan konfirmasi.')
                    ->warning()
                    ->send();

                return;
            }

            Notification::make()
                ->title('Pemulihan selesai')
                ->body(
                    'Data berhasil dipulihkan dari '.$filename.
                    '. Silakan restart aplikasi agar perubahan diterapkan.'
                )
                ->warning()
                ->send();

            $this->restoreCandidate = null;
        } catch (RestoreException $e) {
            Notification::make()
                ->title('Pemulihan gagal')
                ->body(
                    $e->getMessage() ?: 'Arsip tidak dapat dipulihkan.'
                )
                ->danger()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Pemulihan gagal')
                ->body(
                    $e->getMessage() ?: 'Terjadi kesalahan saat memulihkan backup.'
                )
                ->danger()
                ->send();
        }
    }

    /**
     * Tahap 1 hapus:
     * memilih file backup yang ingin dihapus.
     */
    public function requestDelete(string $filename): void
    {
        $filename = basename($filename);

        if (! $this->isValidBackupFilename($filename)) {
            Notification::make()
                ->title('File tidak valid')
                ->body('Hanya file backup SIPETA yang dapat dihapus.')
                ->danger()
                ->send();

            return;
        }

        $disk = Storage::disk(RestoreService::DISK);

        if (! $disk->exists($filename)) {
            Notification::make()
                ->title('Backup tidak ditemukan')
                ->body('File backup tersebut sudah tidak tersedia.')
                ->danger()
                ->send();

            return;
        }

        $this->deleteCandidate = $filename;
        $this->restoreCandidate = null;
    }

    /**
     * Membatalkan penghapusan.
     */
    public function cancelDelete(): void
    {
        $this->deleteCandidate = null;
    }

    /**
     * Tahap 2 hapus:
     * benar-benar menghapus file backup.
     *
     * Catatan:
     * backup_logs TIDAK dihapus.
     * Log tetap dipertahankan sebagai histori audit.
     */
    public function confirmDelete(): void
    {
        $filename = $this->deleteCandidate;

        if ($filename === null) {
            return;
        }

        $filename = basename($filename);

        if (! $this->isValidBackupFilename($filename)) {
            Notification::make()
                ->title('File tidak valid')
                ->body('File tersebut bukan backup SIPETA yang valid.')
                ->danger()
                ->send();

            $this->deleteCandidate = null;

            return;
        }

        $disk = Storage::disk(RestoreService::DISK);

        if (! $disk->exists($filename)) {
            Notification::make()
                ->title('Backup tidak ditemukan')
                ->body('File backup tersebut sudah tidak tersedia.')
                ->warning()
                ->send();

            $this->deleteCandidate = null;

            return;
        }

        try {
            $deleted = $disk->delete($filename);

            if (! $deleted || $disk->exists($filename)) {
                throw new \RuntimeException(
                    'File backup tidak berhasil dihapus dari penyimpanan.'
                );
            }

            Notification::make()
                ->title('Backup berhasil dihapus')
                ->body(
                    $filename.
                    ' telah dihapus dari penyimpanan backup.'
                )
                ->success()
                ->send();

            /*
             * Histori backup_logs sengaja tidak dihapus.
             * Ini menjaga audit trail tetap tersedia.
             */
            $this->deleteCandidate = null;
        } catch (Throwable $e) {
            Notification::make()
                ->title('Gagal menghapus backup')
                ->body(
                    $e->getMessage() ?: 'Backup tidak dapat dihapus.'
                )
                ->danger()
                ->send();
        }
    }

    /**
     * Tombol header.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('buatBackup')
                ->label('Buat Backup')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->createBackup()),
        ];
    }

    /**
     * Ambil HANYA backup ZIP SIPETA.
     *
     * .gitignore, folder, file lain, dsb tidak akan ditampilkan.
     *
     * @return Collection<int, array{
     *     filename: string,
     *     size: int,
     *     lastModified: int
     * }>
     */
    public function backups(): Collection
    {
        $disk = Storage::disk(RestoreService::DISK);

        return collect($disk->files())
            ->map(function (string $path) use ($disk): ?array {
                $filename = basename($path);

                /*
                 * Hanya backup yang dibuat oleh BackupService.
                 *
                 * Format:
                 * backup_YYYY-MM-DD_HHMMSS.zip
                 */
                if (! $this->isValidBackupFilename($filename)) {
                    return null;
                }

                if (! $disk->exists($path)) {
                    return null;
                }

                return [
                    'filename' => $filename,
                    'size' => $disk->size($path),
                    'lastModified' => $disk->lastModified($path),
                ];
            })
            ->filter()
            ->sortByDesc('lastModified')
            ->values();
    }

    /**
     * Validasi nama backup.
     *
     * Contoh valid:
     * backup_2026-08-07_190114.zip
     *
     * Contoh tidak valid:
     * .gitignore
     * database.sql
     * file.zip
     */
    private function isValidBackupFilename(string $filename): bool
    {
        return preg_match(
            '/^backup_\d{4}-\d{2}-\d{2}_\d{6}\.zip$/',
            basename($filename),
        ) === 1;
    }
}
