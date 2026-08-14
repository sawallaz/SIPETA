<?php

namespace Tests\Feature\Phase2;

use App\Models\KartuKeluarga;
use App\Models\KkPhoto;
use App\Services\KkPhotoService;
use Illuminate\Support\Facades\Storage;

/**
 * 2C — versioned KK photo archive.
 *
 * Replacing a KK photo must archive the old version (is_active = false,
 * file retained) instead of deleting it. The service layer is the single
 * owner of the lifecycle and must never leave more than one active photo.
 */
class KkPhotoServiceArchiveTest extends Phase2TestCase
{
    public function test_store_for_kk_creates_a_single_active_photo(): void
    {
        Storage::fake('kk_uploads');

        $kk = KartuKeluarga::factory()->create();
        $path = $this->putDummyPhoto();

        app(KkPhotoService::class)->storeForKk($kk->id, $path);

        $this->assertSame(1, KkPhoto::where('kk_id', $kk->id)->count());
        $this->assertSame(
            1,
            KkPhoto::where('kk_id', $kk->id)->where('is_active', true)->count()
        );
        $this->assertNotNull($kk->activePhoto());
    }

    public function test_replacing_photo_archives_old_instead_of_deleting(): void
    {
        Storage::fake('kk_uploads');

        $kk = KartuKeluarga::factory()->create();
        $firstPath = $this->putDummyPhoto();
        $secondPath = $this->putDummyPhoto();

        $first = app(KkPhotoService::class)->storeForKk($kk->id, $firstPath);
        $second = app(KkPhotoService::class)->storeForKk($kk->id, $secondPath);

        // Old photo is retained as archive, never deleted.
        $this->assertSame(2, KkPhoto::where('kk_id', $kk->id)->count());

        $this->assertFalse(
            $first->fresh()->is_active,
            'Old photo must be archived (is_active = false).'
        );
        $this->assertTrue(
            $second->fresh()->is_active,
            'New photo must be the active one.'
        );

        // Exactly one active photo at all times.
        $this->assertSame(
            1,
            KkPhoto::where('kk_id', $kk->id)->where('is_active', true)->count()
        );

        // The archived file is still on disk.
        Storage::disk('kk_uploads')->assertExists($first->fresh()->storage_path);
    }

    private function putDummyPhoto(): string
    {
        $name = 'dummy-'.uniqid().'.jpg';

        // Not decoded by storeForKk (only copied); JPEG signature is enough
        // to satisfy any downstream guard but irrelevant here.
        Storage::disk('kk_uploads')->put($name, "\xFF\xD8\xFF\xE0dummy-jpg-content");

        return $name;
    }
}
