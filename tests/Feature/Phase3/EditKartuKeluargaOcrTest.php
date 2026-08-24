<?php

namespace Tests\Feature\Phase3;

use App\Filament\Resources\KartuKeluargas\Pages\EditKartuKeluarga;
use App\Models\AreaUnit;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\Occupation;
use App\Models\Religion;
use App\Models\Rt;
use App\Services\KkPhotoService;
use App\Services\OcrProcessingService;
use App\Services\ParsedOcrResult;
use App\Services\ParsedResident;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;

class EditKartuKeluargaOcrTest extends Phase3ResourceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(KkPhotoService::DISK);
    }

    public function test_upload_photo_does_not_automatically_trigger_ocr_on_edit(): void
    {
        $kk = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010001',
            'address' => 'Jl. Asli No. 1',
        ]);

        $file = UploadedFile::fake()->image('kk.jpg', 1200, 800);

        $component = Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->set('data.kk_photo', $file);

        // OCR preview and modal should be closed because upload != scan
        $this->assertFalse($component->get('isOcrModalOpen'));
        $this->assertSame([], $component->get('ocrPreview'));
        $this->assertSame('Jl. Asli No. 1', $component->get('data.address'));
    }

    public function test_manual_scan_opens_modal_without_mutating_form_data(): void
    {
        $kk = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010001',
            'address' => 'Jl. Asli No. 1',
            'postal_code' => '90001',
        ]);

        $file = UploadedFile::fake()->image('kk.jpg', 1200, 800);

        $mockOcr = Mockery::mock(OcrProcessingService::class);
        $mockOcr->shouldReceive('start')->once();
        $mockOcr->shouldReceive('extract')->once();
        $mockOcr->shouldReceive('parse')->once()->andReturn(
            new ParsedOcrResult(
                confidence: 94.0,
                lowConfidence: false,
                kkNumber: '7371010101010001',
                address: 'Jl. Scanned OCR No. 99',
                rt: '01',
                rw: '02',
                lingkungan: null,
                members: [
                    new ParsedResident(
                        nama: 'BUDI SANTOSO',
                        nik: '7371010101010099',
                        gender: 'LAKI_LAKI',
                        birthPlace: 'MAKASSAR',
                        birthDate: '1985-05-12',
                        religion: 'ISLAM',
                        education: 'SLTA/SEDERAJAT',
                        occupation: 'WIRASWASTA',
                        maritalStatus: 'KAWIN',
                        familyRelation: 'KEPALA_KELUARGA',
                        confidence: 95.0,
                        lowConfidence: false,
                    ),
                ],
                warnings: [],
                validationErrors: [],
                durationMs: 120.0,
                postalCode: '90711',
            )
        );
        $this->app->instance(OcrProcessingService::class, $mockOcr);

        $component = Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->set('data.kk_photo', $file)
            ->call('scanFoto');

        // Form state should remain unchanged (preserves original input)
        $this->assertSame('Jl. Asli No. 1', $component->get('data.address'));
        $this->assertSame('90001', $component->get('data.postal_code'));

        // Single modal overlay is opened with preview
        $this->assertTrue($component->get('isOcrModalOpen'));
        $preview = $component->get('ocrPreview');
        $this->assertNotEmpty($preview);
        $this->assertSame('Jl. Scanned OCR No. 99', $preview['address']);
        $this->assertSame('90711', $preview['postal_code']);
        $this->assertCount(1, $preview['members']);
    }

    public function test_close_ocr_modal_closes_dialog_and_leaves_form_intact(): void
    {
        $kk = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010001',
            'address' => 'Jl. Asli No. 1',
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
                address: 'Jl. Scanned',
                rt: null,
                rw: null,
                lingkungan: null,
                members: [],
                warnings: [],
                validationErrors: [],
                durationMs: 100.0,
                postalCode: '90711',
            )
        );
        $this->app->instance(OcrProcessingService::class, $mockOcr);

        $component = Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->set('data.kk_photo', $file)
            ->call('scanFoto')
            ->assertSet('isOcrModalOpen', true)
            ->call('closeOcrModal');

        $this->assertFalse($component->get('isOcrModalOpen'));
        $this->assertSame([], $component->get('ocrPreview'));
        $this->assertSame('Jl. Asli No. 1', $component->get('data.address'));
    }

    public function test_apply_ocr_result_updates_form_state_and_closes_modal(): void
    {
        $area = AreaUnit::factory()->create(['name' => 'RW 01']);
        $rt = Rt::factory()->create(['area_unit_id' => $area->id, 'number' => '01']);

        $kk = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010001',
            'address' => 'Jl. Asli No. 1',
            'postal_code' => '90001',
            'rt_id' => null,
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
                address: 'Jl. Hasil OCR Baru',
                rt: '01',
                rw: null,
                lingkungan: null,
                members: [],
                warnings: [],
                validationErrors: [],
                durationMs: 100.0,
                postalCode: '90711',
            )
        );
        $this->app->instance(OcrProcessingService::class, $mockOcr);

        $component = Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->set('data.kk_photo', $file)
            ->call('scanFoto')
            ->call('applyOcrResult');

        // Modal closed
        $this->assertFalse($component->get('isOcrModalOpen'));

        // Form state is updated
        $this->assertSame('Jl. Hasil OCR Baru', $component->get('data.address'));
        $this->assertSame('90711', $component->get('data.postal_code'));
        $this->assertSame($rt->id, $component->get('data.rt_id'));
    }

    public function test_ocr_conflict_with_another_existing_kk_is_detected_and_blocks_application(): void
    {
        $kkA = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010001',
            'address' => 'Alamat KK A',
        ]);

        $kkB = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010002',
            'address' => 'Alamat KK B',
        ]);

        $file = UploadedFile::fake()->image('kk.jpg', 1200, 800);

        // OCR parses KK B's number while editing KK A
        $mockOcr = Mockery::mock(OcrProcessingService::class);
        $mockOcr->shouldReceive('start')->once();
        $mockOcr->shouldReceive('extract')->once();
        $mockOcr->shouldReceive('parse')->once()->andReturn(
            new ParsedOcrResult(
                confidence: 90.0,
                lowConfidence: false,
                kkNumber: '7371010101010002',
                address: 'Jl. Konflik No. 5',
                rt: null,
                rw: null,
                lingkungan: null,
                members: [],
                warnings: [],
                validationErrors: [],
                durationMs: 100.0,
                postalCode: '90711',
            )
        );
        $this->app->instance(OcrProcessingService::class, $mockOcr);

        $component = Livewire::test(EditKartuKeluarga::class, ['record' => $kkA->getKey()])
            ->set('data.kk_photo', $file)
            ->call('scanFoto');

        // Conflict flagged
        $this->assertTrue($component->get('ocrPreview.is_kk_conflict'));
        $this->assertSame('7371010101010002', $component->get('duplicateKk.number'));

        // Attempting to apply should be blocked
        $component->call('applyOcrResult');

        // KK A form state remains unchanged
        $this->assertSame('7371010101010001', $component->get('data.kk_number'));
        $this->assertSame('Alamat KK A', $component->get('data.address'));
    }

    public function test_save_commits_form_and_staged_ocr_members_in_one_atomic_transaction(): void
    {
        Religion::factory()->create(['name' => 'Islam']);
        Education::factory()->create(['name' => 'SLTA/Sederajat']);
        Occupation::factory()->create(['name' => 'Wiraswasta']);

        $kk = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010001',
            'address' => 'Jl. Lama',
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
                address: 'Jl. Diupdate Dari OCR',
                rt: null,
                rw: null,
                lingkungan: null,
                members: [
                    new ParsedResident(
                        nama: 'HASANUDDIN',
                        nik: '7371010101010088',
                        gender: 'LAKI_LAKI',
                        birthPlace: 'MAKASSAR',
                        birthDate: '1980-01-01',
                        religion: 'ISLAM',
                        education: 'SLTA/SEDERAJAT',
                        occupation: 'WIRASWASTA',
                        maritalStatus: 'KAWIN',
                        familyRelation: 'KEPALA_KELUARGA',
                        confidence: 92.0,
                        lowConfidence: false,
                    ),
                ],
                warnings: [],
                validationErrors: [],
                durationMs: 100.0,
                postalCode: '90711',
            )
        );
        $this->app->instance(OcrProcessingService::class, $mockOcr);

        // 1. Scan -> 2. Apply -> 3. Save via standard "Simpan Perubahan"
        $component = Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->set('data.kk_photo', $file)
            ->call('scanFoto')
            ->call('applyOcrResult')
            ->call('save')
            ->assertHasNoFormErrors();

        // KK address updated
        $this->assertSame('Jl. Diupdate Dari OCR', $kk->refresh()->address);

        // Member persisted and linked to this KK
        $this->assertDatabaseHas('penduduk', [
            'nik' => '7371010101010088',
            'full_name' => 'HASANUDDIN',
            'kk_id' => $kk->id,
        ]);
    }
}
