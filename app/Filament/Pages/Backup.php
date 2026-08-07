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

/**
 * Phase 6.4 — operator-facing Backup & Restore page.
 *
 * The "Backup" menu in the five-menu navigation (`.ai/workflow.md` §1, §14,
 * §15). Wires the Phase 6.2 `BackupService` and the Phase 6.3 `RestoreService`
 * onto a single page: a "Buat Backup" action (§14), a list of the backups on
 * the `db_backups` disk, and a two-step restore per backup — choose the
 * archive then explicitly confirm (§15). The confirmation step is the
 * FR-BR-05 explicit-confirmation gate; an integrity failure surfaces as a
 * friendly warning (FR-BR-04) and a successful restore notifies the operator
 * to restart the application (FR-BR-06).
 */
class Backup extends Page
{
    protected string $view = 'filament.pages.backup';

    protected static ?string $navigationLabel = 'Backup';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    /** ZIP chosen for restore, shown in the confirmation step until confirmed. */
    public ?string $restoreCandidate = null;

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
     * §14 — Create a backup archive through BackupService.
     */
    public function createBackup(): void
    {
        $result = app(BackupService::class)->create(auth()->user());

        if ($result->isDuplicate()) {
            Notification::make()
                ->title('Backup sudah ada')
                ->body('Arsip '.$result->filename.' sudah pernah dibuat. Buat ulang tidak diperlukan.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Backup berhasil')
            ->body('Arsip '.$result->filename.' tersimpan pada lokasi backup.')
            ->success()
            ->send();
    }

    /**
     * §15 step 1 — choose the backup archive to restore.
     */
    public function requestRestore(string $filename): void
    {
        if (! str_ends_with($filename, '.zip')) {
            Notification::make()
                ->title('Arsip tidak valid')
                ->body('Hanya arsip ZIP yang dapat dipulihkan.')
                ->danger()
                ->send();

            return;
        }

        $this->restoreCandidate = $filename;
    }

    public function cancelRestore(): void
    {
        $this->restoreCandidate = null;
    }

    /**
     * §15 step 2 — restore the confirmed archive (FR-BR-05 explicit
     * confirmation already granted by reaching this step). Handles FR-BR-04
     * integrity failures and FR-BR-06 restart advice.
     */
    public function confirmRestore(): void
    {
        $filename = $this->restoreCandidate;

        if ($filename === null) {
            $this->cancelRestore();

            return;
        }

        try {
            $result = app(RestoreService::class)->restore($filename, auth()->user(), true);

            if (! $result->isRestored()) {
                Notification::make()
                    ->title('Pemulihan tidak diproses')
                    ->body('Pemulihan memerlukan konfirmasi.')
                    ->warning()
                    ->send();

                return;
            }

            // FR-BR-06: after a restore the operator must restart the application.
            Notification::make()
                ->title('Pemulihan selesai')
                ->body('Data dipulihkan dari '.$filename.'. Silakan restart aplikasi agar perubahan diterapkan.')
                ->warning()
                ->send();

            $this->restoreCandidate = null;
        } catch (RestoreException $e) {
            Notification::make()
                ->title('Pemulihan gagal')
                ->body($e->getMessage() ?: 'Arsip tidak dapat dipulihkan.')
                ->danger()
                ->send();
        }
    }

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
     * Every backup archive currently stored on the `db_backups` disk, newest
     * first.
     *
     * @return Collection<int, array{filename: string, size: int, lastModified: int}>
     */
    public function backups(): Collection
    {
        $disk = Storage::disk(RestoreService::DISK);

        return collect($disk->files())
            ->map(function (string $path) use ($disk): array {
                return [
                    'filename' => basename($path),
                    'size' => $disk->size($path),
                    'lastModified' => $disk->lastModified($path),
                ];
            })
            ->sortByDesc('lastModified')
            ->values();
    }
}
