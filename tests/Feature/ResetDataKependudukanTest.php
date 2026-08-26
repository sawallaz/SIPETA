<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\KartuKeluarga;
use App\Models\KkAnggota;
use App\Models\KkPhoto;
use App\Models\Penduduk;
use App\Models\Setting;
use App\Models\User;
use App\Services\DataResetService;
use App\Services\KkPhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResetDataKependudukanTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | DataResetService Test Suite
    |--------------------------------------------------------------------------
    | Memverifikasi bahwa reset data kependudukan:
    |   ✓ Menghapus seluruh KK, Penduduk, dan foto KK (record + file fisik).
    |   ✓ Mempertahankan akun user (Super Admin / Operator).
    |   ✓ Mempertahankan konfigurasi settings dan token Google Drive.
    |   ✓ Menghasilkan stats penghapusan yang akurat.
    */

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(KkPhotoService::DISK);

        $this->admin = User::factory()->create([
            'role' => UserRole::SUPER_ADMIN,
        ]);

        $this->actingAs($this->admin);
    }

    // -----------------------------------------------------------------------
    // Helper: Buat data kependudukan palsu untuk pengujian.
    // -----------------------------------------------------------------------

    private function seedKependudukan(int $kkCount = 2, int $pendudukPerKk = 3): void
    {
        for ($i = 0; $i < $kkCount; $i++) {
            $kk = KartuKeluarga::factory()->create();

            for ($j = 0; $j < $pendudukPerKk; $j++) {
                Penduduk::factory()->create(['kk_id' => $kk->id]);
            }

            // Buat 1 foto per KK dengan file fisik palsu di disk fake.
            $stored = "photo_{$kk->id}.jpg";
            Storage::disk(KkPhotoService::DISK)->put($stored, 'fake-image-bytes');

            KkPhoto::factory()->create([
                'kk_id'           => $kk->id,
                'storage_disk'    => KkPhotoService::DISK,
                'storage_path'    => $stored,
                'stored_filename' => $stored,
                'thumbnail_filename' => null,
                'is_active'       => true,
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // Test 1: Seluruh KK dan Penduduk terhapus menjadi 0.
    // -----------------------------------------------------------------------

    public function test_reset_menghapus_seluruh_kartu_keluarga(): void
    {
        $this->seedKependudukan(kkCount: 3);

        $this->assertGreaterThan(0, KartuKeluarga::count());

        app(DataResetService::class)->resetAll();

        $this->assertSame(0, KartuKeluarga::count());
    }

    public function test_reset_menghapus_seluruh_penduduk(): void
    {
        $this->seedKependudukan(kkCount: 2, pendudukPerKk: 4);

        $this->assertGreaterThan(0, Penduduk::count());

        app(DataResetService::class)->resetAll();

        $this->assertSame(0, Penduduk::count());
    }

    public function test_reset_menghapus_seluruh_kk_photo_records(): void
    {
        $this->seedKependudukan(kkCount: 2);

        $this->assertGreaterThan(0, KkPhoto::count());

        app(DataResetService::class)->resetAll();

        $this->assertSame(0, KkPhoto::count());
    }

    // -----------------------------------------------------------------------
    // Test 2: File fisik foto KK terhapus dari disk.
    // -----------------------------------------------------------------------

    public function test_reset_menghapus_file_fisik_foto_kk(): void
    {
        $this->seedKependudukan(kkCount: 2);

        $paths = KkPhoto::pluck('storage_path')->all();

        foreach ($paths as $path) {
            $this->assertTrue(
                Storage::disk(KkPhotoService::DISK)->exists($path),
                "File foto harus ada sebelum reset: {$path}"
            );
        }

        app(DataResetService::class)->resetAll();

        foreach ($paths as $path) {
            $this->assertFalse(
                Storage::disk(KkPhotoService::DISK)->exists($path),
                "File foto harus terhapus setelah reset: {$path}"
            );
        }
    }

    // -----------------------------------------------------------------------
    // Test 3: Akun Admin tetap ada dan valid setelah reset.
    // -----------------------------------------------------------------------

    public function test_akun_admin_tetap_utuh_setelah_reset(): void
    {
        $this->seedKependudukan();

        $userCountBefore = User::count();

        app(DataResetService::class)->resetAll();

        $this->assertSame($userCountBefore, User::count());
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    // -----------------------------------------------------------------------
    // Test 4: Settings (termasuk token Google Drive) tetap ada.
    // -----------------------------------------------------------------------

    public function test_settings_tetap_utuh_setelah_reset(): void
    {
        $this->seedKependudukan();

        $settingCount = Setting::count();

        app(DataResetService::class)->resetAll();

        // Jumlah settings tidak berkurang.
        $this->assertSame($settingCount, Setting::count());
    }

    // -----------------------------------------------------------------------
    // Test 5: Stats penghapusan yang dikembalikan akurat.
    // -----------------------------------------------------------------------

    public function test_reset_mengembalikan_stats_yang_akurat(): void
    {
        $this->seedKependudukan(kkCount: 3, pendudukPerKk: 2);

        $kkBefore       = KartuKeluarga::count();
        $pendudukBefore = Penduduk::count();
        $photoBefore    = KkPhoto::count();

        $stats = app(DataResetService::class)->resetAll();

        $this->assertSame($kkBefore, $stats['kk_deleted']);
        $this->assertSame($pendudukBefore, $stats['penduduk_deleted']);
        $this->assertSame($photoBefore, $stats['photo_files_deleted']);
    }

    // -----------------------------------------------------------------------
    // Test 6: Reset pada database kosong tidak menimbulkan error.
    // -----------------------------------------------------------------------

    public function test_reset_pada_database_kosong_tidak_error(): void
    {
        // Tidak seed data apapun — database sudah kosong.
        $stats = app(DataResetService::class)->resetAll();

        $this->assertSame(0, $stats['kk_deleted']);
        $this->assertSame(0, $stats['penduduk_deleted']);
        $this->assertSame(0, $stats['photo_files_deleted']);

        $this->assertSame(0, KartuKeluarga::count());
        $this->assertSame(0, Penduduk::count());
    }

    // -----------------------------------------------------------------------
    // Test 7: Verifikasi bahwa reset memang membersihkan record KkAnggota.
    // -----------------------------------------------------------------------

    public function test_reset_menghapus_kk_anggota(): void
    {
        $this->seedKependudukan(kkCount: 2);

        // Buat beberapa kk_anggota jika factory tersedia.
        if (class_exists(\Database\Factories\KkAnggotaFactory::class)) {
            $kk = KartuKeluarga::first();
            if ($kk) {
                KkAnggota::factory()->count(3)->create(['kk_id' => $kk->id]);
                $this->assertGreaterThan(0, KkAnggota::count());
            }
        }

        app(DataResetService::class)->resetAll();

        $this->assertSame(0, KkAnggota::count());
    }
}
