<?php

namespace App\Filament\Resources\KartuKeluargas\Pages;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaDeleteGuard;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\KartuKeluargas\Schemas\KartuKeluargaForm;
use App\Services\KkPhotoService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditKartuKeluarga extends EditRecord
{
    protected static string $resource = KartuKeluargaResource::class;

    /**
     * Full-width content so the two-card layout (Dokumen KK | Data KK) spans
     * the whole panel. The panel already defaults to Width::Full, this just
     * makes the intent explicit.
     *
     * Anggota Keluarga are NOT edited inline here — they are managed from the
     * Penduduks relation manager table below (View/Edit/Delete on each row,
     * via PendudukForm), so the household KK edit and the resident edit stay
     * as two distinct, non-confusing surfaces.
     */
    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getTitle(): string
    {
        return 'Ubah Kartu Keluarga';
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Perubahan Kartu Keluarga berhasil disimpan';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(
            KartuKeluargaForm::components()
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Hapus')
                ->modalHeading('Hapus Kartu Keluarga')
                ->modalDescription(
                    'Kartu Keluarga hanya dapat dihapus jika tidak memiliki anggota atau data histori yang masih terhubung. Penghapusan tidak akan menghapus anggota, foto, maupun riwayat secara otomatis.'
                )
                ->modalSubmitActionLabel('Ya, Hapus')
                ->modalCancelActionLabel('Batal')
                ->before(
                    function (Model $record): void {
                        KartuKeluargaDeleteGuard::assertDeletable($record);
                    }
                )
                ->successNotificationTitle(
                    'Kartu Keluarga berhasil dihapus'
                ),
        ];
    }

    /**
     * Save KK data + swap the archived KK photo. Member management lives in the
     * Penduduks relation manager, so nothing about anggota is touched here.
     */
    protected function handleRecordUpdate(
        Model $record,
        array $data,
    ): Model {
        return DB::transaction(function () use ($record, $data): Model {
            $photoPath = $data['kk_photo'] ?? null;

            unset($data['kk_photo']);

            $record->update($data);

            if (blank($photoPath)) {
                return $record->fresh();
            }

            $oldPhoto = $record
                ->kkPhotos()
                ->where('is_active', true)
                ->latest('id')
                ->first();

            $newPhoto = app(KkPhotoService::class)->storeForKk(
                $record->id,
                $photoPath,
                null,
                auth()->id()
            );

            if ($oldPhoto !== null && $oldPhoto->id !== $newPhoto->id) {
                app(KkPhotoService::class)->deletePhoto($oldPhoto);
            }

            return $record->fresh();
        });
    }
}
