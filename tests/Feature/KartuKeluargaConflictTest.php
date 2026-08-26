<?php

namespace Tests\Feature;

use App\Enums\FamilyRelation;
use App\Filament\Resources\KartuKeluargas\Pages\EditKartuKeluarga;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\User;
use App\Services\KkPhotoService;
use App\Services\OcrProcessingService;
use App\Services\ParsedOcrResult;
use App\Services\ParsedResident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class KartuKeluargaConflictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(KkPhotoService::DISK);

        $user = User::factory()->create();
        $this->actingAs($user);
    }

    public function test_ocr_with_duplicate_kk_number_triggers_conflict_and_blocks_overwrite(): void
    {
        $kk1 = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010001',
            'address' => 'Jl. Asli KK1 No. 1',
        ]);

        $kk2 = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010002',
            'address' => 'Jl. Asli KK2 No. 2',
        ]);

        Penduduk::factory()->create([
            'kk_id' => $kk2->id,
            'full_name' => 'Ahmad Dahlan',
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
        ]);

        $file = UploadedFile::fake()->image('kk_duplicate.jpg', 1200, 800);

        $mockOcr = Mockery::mock(OcrProcessingService::class);
        $mockOcr->shouldReceive('start')->once();
        $mockOcr->shouldReceive('extract')->once();
        $mockOcr->shouldReceive('parse')->once()->andReturn(
            new ParsedOcrResult(
                confidence: 95.0,
                lowConfidence: false,
                kkNumber: '7371010101010002', // Matches KK2
                address: 'Jl. Scanned Overwrite No. 99',
                rt: '01',
                rw: '02',
                lingkungan: null,
                members: [],
                warnings: [],
                validationErrors: [],
                durationMs: 150.0,
                postalCode: '90001',
            )
        );
        $this->app->instance(OcrProcessingService::class, $mockOcr);

        $component = Livewire::test(EditKartuKeluarga::class, ['record' => $kk1->getKey()])
            ->set('data.kk_photo', $file)
            ->call('scanFoto');

        $this->assertTrue($component->get('isOcrModalOpen'));
        $preview = $component->get('ocrPreview');
        $this->assertTrue($preview['is_kk_conflict']);
        $this->assertSame((string) $kk2->id, (string) $preview['conflict_kk']['id']);
        $this->assertSame('7371010101010002', $preview['conflict_kk']['number']);
        $this->assertSame('Ahmad Dahlan', $preview['conflict_kk']['kepala']);

        // Attempting to apply OCR result must be blocked
        $component->call('applyOcrResult');

        $component->assertNotified('⚠️ Nomor KK / NIK Sudah Terdaftar di Sistem!');

        // KK1 address and number must remain untouched
        $this->assertSame('Jl. Asli KK1 No. 1', $component->get('data.address'));
        $this->assertSame('7371010101010001', $component->get('data.kk_number'));
        $this->assertSame('Jl. Asli KK1 No. 1', $kk1->fresh()->address);
    }

    public function test_ocr_with_duplicate_nik_triggers_conflict_and_blocks_overwrite(): void
    {
        $kk1 = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010001',
            'address' => 'Jl. Asli KK1 No. 1',
        ]);

        $kk2 = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010002',
            'address' => 'Jl. Asli KK2 No. 2',
        ]);

        $budi = Penduduk::factory()->create([
            'kk_id' => $kk2->id,
            'nik' => '7371010101019999',
            'full_name' => 'Budi Santoso',
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
        ]);

        $file = UploadedFile::fake()->image('kk_resident_dup.jpg', 1200, 800);

        $mockOcr = Mockery::mock(OcrProcessingService::class);
        $mockOcr->shouldReceive('start')->once();
        $mockOcr->shouldReceive('extract')->once();
        $mockOcr->shouldReceive('parse')->once()->andReturn(
            new ParsedOcrResult(
                confidence: 95.0,
                lowConfidence: false,
                kkNumber: '7371010101010001', // Same KK number or new
                address: 'Jl. Scanned No. 10',
                rt: '01',
                rw: '02',
                lingkungan: null,
                members: [
                    new ParsedResident(
                        nama: 'Budi Santoso',
                        nik: '7371010101019999', // Belongs to KK2!
                        gender: 'LAKI-LAKI',
                        birthPlace: 'Makassar',
                        birthDate: '1990-01-01',
                        religion: 'Islam',
                        education: 'S1',
                        occupation: 'Wiraswasta',
                        maritalStatus: 'KAWIN',
                        familyRelation: 'KEPALA KELUARGA',
                        confidence: 95.0,
                        lowConfidence: false,
                    ),
                ],
                warnings: [],
                validationErrors: [],
                durationMs: 150.0,
                postalCode: '90001',
            )
        );
        $this->app->instance(OcrProcessingService::class, $mockOcr);

        $component = Livewire::test(EditKartuKeluarga::class, ['record' => $kk1->getKey()])
            ->set('data.kk_photo', $file)
            ->call('scanFoto');

        $this->assertTrue($component->get('isOcrModalOpen'));
        $preview = $component->get('ocrPreview');
        $this->assertTrue($preview['is_kk_conflict']);
        $this->assertStringContainsString('7371010101019999', $preview['conflict_reason']);
        $this->assertSame((string) $kk2->id, (string) $preview['conflict_kk']['id']);

        // Attempting to apply OCR result must be blocked
        $component->call('applyOcrResult');

        $component->assertNotified('⚠️ Nomor KK / NIK Sudah Terdaftar di Sistem!');

        // Budi must still belong to KK2
        $this->assertSame($kk2->id, $budi->fresh()->kk_id);
    }

    public function test_conflict_modal_renders_navigation_links_and_warning(): void
    {
        $kk1 = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010001',
        ]);

        $kk2 = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010002',
        ]);

        Penduduk::factory()->create([
            'kk_id' => $kk2->id,
            'full_name' => 'Siti Nurhaliza',
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
        ]);

        $file = UploadedFile::fake()->image('kk_dup.jpg', 1200, 800);

        $mockOcr = Mockery::mock(OcrProcessingService::class);
        $mockOcr->shouldReceive('start')->once();
        $mockOcr->shouldReceive('extract')->once();
        $mockOcr->shouldReceive('parse')->once()->andReturn(
            new ParsedOcrResult(
                confidence: 95.0,
                lowConfidence: false,
                kkNumber: '7371010101010002',
                address: 'Jl. Test No. 5',
                rt: '01',
                rw: '02',
                lingkungan: null,
                members: [],
                warnings: [],
                validationErrors: [],
                durationMs: 150.0,
                postalCode: '90001',
            )
        );
        $this->app->instance(OcrProcessingService::class, $mockOcr);

        $component = Livewire::test(EditKartuKeluarga::class, ['record' => $kk1->getKey()])
            ->set('data.kk_photo', $file)
            ->call('scanFoto');

        $component
            ->assertSee('⚠️ Nomor KK / NIK Sudah Terdaftar di Sistem!')
            ->assertSee('Lihat / Edit Data KK Tersebut')
            ->assertSee('Batal / Gunakan Foto Lain')
            ->assertSee('Terapkan dinonaktifkan (Konflik Data)');
    }

    public function test_cancelling_ocr_modal_preserves_family_members_integrity(): void
    {
        $kk = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010001',
            'address' => 'Jl. Original No. 1',
        ]);

        $originalMember = Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'full_name' => 'Anggota Asli',
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
        ]);

        $file = UploadedFile::fake()->image('kk.jpg', 1200, 800);

        $mockOcr = Mockery::mock(OcrProcessingService::class);
        $mockOcr->shouldReceive('start')->once();
        $mockOcr->shouldReceive('extract')->once();
        $mockOcr->shouldReceive('parse')->once()->andReturn(
            new ParsedOcrResult(
                confidence: 90.0,
                lowConfidence: false,
                kkNumber: '7371010101010001',
                address: 'Jl. OCR No. 20',
                rt: '01',
                rw: '02',
                lingkungan: null,
                members: [
                    new ParsedResident(
                        nama: 'Orang Baru Dari OCR',
                        nik: '7371010101018888',
                        gender: 'LAKI-LAKI',
                        birthPlace: 'Makassar',
                        birthDate: '1995-05-05',
                        religion: 'Islam',
                        education: 'SMA',
                        occupation: 'Wiraswasta',
                        maritalStatus: 'BELUM KAWIN',
                        familyRelation: 'ANAK',
                        confidence: 90.0,
                        lowConfidence: false,
                    ),
                ],
                warnings: [],
                validationErrors: [],
                durationMs: 150.0,
                postalCode: '90001',
            )
        );
        $this->app->instance(OcrProcessingService::class, $mockOcr);

        $component = Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->set('data.kk_photo', $file)
            ->call('scanFoto');

        $this->assertTrue($component->get('isOcrModalOpen'));

        // Operator decides to cancel
        $component->call('closeOcrModal');

        $this->assertFalse($component->get('isOcrModalOpen'));
        $this->assertSame([], $component->get('ocrPreview'));

        // Database and form data must remain unchanged
        $this->assertSame('Jl. Original No. 1', $component->get('data.address'));
        $this->assertCount(1, $kk->fresh()->penduduks);
        $this->assertSame('Anggota Asli', $kk->fresh()->penduduks->first()->full_name);
        $this->assertNull(Penduduk::where('nik', '7371010101018888')->first());
    }
}
