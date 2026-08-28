<?php

namespace Tests\Feature;

use App\Filament\Resources\KartuKeluargas\Pages\CreateKartuKeluarga;
use App\Models\AreaUnit;
use App\Models\Rt;
use App\Models\User;
use Database\Seeders\SystemReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RealCameraPhotoOcrValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemReferenceSeeder::class);
        $this->actingAs(User::factory()->create(['role' => 'SUPER_ADMIN']));

        // Master RW 02 & RT 01
        $rw = AreaUnit::firstOrCreate(['name' => 'RW 02', 'type' => 'rw', 'code' => '02']);
        Rt::firstOrCreate(['number' => '01', 'area_unit_id' => $rw->id]);
    }

    public function test_livewire_create_kk_from_real_camera_phone_photo_populates_four_members(): void
    {
        Storage::fake('kk_uploads');
        Storage::fake('ocr_temp');

        // Create phone camera photo on dark desk canvas
        $src = imagecreatefrompng(base_path('tests/Fixtures/sample_kk.png'));
        $sw = imagesx($src);
        $sh = imagesy($src);

        $canvas = imagecreatetruecolor(3600, 2700);
        $darkDesk = imagecolorallocate($canvas, 30, 25, 20);
        imagefill($canvas, 0, 0, $darkDesk);
        imagecopyresampled($canvas, $src, 500, 430, 0, 0, 2600, (int)($sh * (2600/$sw)), $sw, $sh);

        $tmpFile = tempnam(sys_get_temp_dir(), 'kk_phone_') . '.png';
        imagepng($canvas, $tmpFile);
        imagedestroy($canvas);
        imagedestroy($src);

        $fileContent = file_get_contents($tmpFile);
        $uploaded = UploadedFile::fake()->createWithContent('kk_phone_desk.png', $fileContent);

        Livewire::test(CreateKartuKeluarga::class)
            ->set('data.kk_photo', $uploaded)
            ->call('scanFoto')
            ->assertHasNoErrors()
            ->assertSet('data.kk_number', '7304012304990001')
            ->assertSet('data.address', 'JL. POROS PARE-PARE NO. 45')
            ->assertCount('data.anggota', 4);

        @unlink($tmpFile);
    }

    public function test_livewire_create_kk_from_five_member_photo_extracts_clean_columns(): void
    {
        Storage::fake('kk_uploads');
        Storage::fake('ocr_temp');

        $fixturePath = base_path('tests/Fixtures/photo_kk_5_anggota.png');
        $this->assertFileExists($fixturePath);

        $fileContent = file_get_contents($fixturePath);
        $uploaded = UploadedFile::fake()->createWithContent('photo_5_members.png', $fileContent);

        $test = Livewire::test(CreateKartuKeluarga::class)
            ->set('data.kk_photo', $uploaded)
            ->call('scanFoto')
            ->assertHasNoErrors()
            ->assertSet('data.kk_number', '3271012506140001')
            ->assertCount('data.anggota', 5);

        $anggota = $test->get('data.anggota');
        $this->assertCount(5, $anggota);

        // Anti-contamination verification on every member name
        $prohibitedWords = ['LAKI-LAKI', 'PEREMPUAN', 'ISLAM', 'SEMARANG', 'KARYAWAN', 'PELAJAR', 'KAWIN'];

        foreach ($anggota as $idx => $m) {
            $nama = mb_strtoupper($m['nama'] ?? '');
            $this->assertNotEmpty($nama, "Member #" . ($idx + 1) . " should have a non-empty name");

            foreach ($prohibitedWords as $bad) {
                $this->assertStringNotContainsString(
                    $bad,
                    $nama,
                    "Member #" . ($idx + 1) . " name '{$nama}' is contaminated with '$bad'"
                );
            }
        }
    }
}
