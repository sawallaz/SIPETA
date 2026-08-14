<?php

namespace Tests\Feature\Phase3;

use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\PendudukDocument;
use App\Models\Rt;
use App\Models\User;
use App\Services\PendudukDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Tests\TestCase;

/**
 * Dokumen pendukung anggota KK (KTP + Akta Kelahiran).
 *
 * Anggota KK adalah Penduduk yang sama, sehingga dokumennya disimpan di
 * tabel penduduk_documents lewat PendudukDocumentService — BUKAN sebagai
 * kolom baru pada tabel penduduk.
 *
 * Kedua dokumen bersifat OPSIONAL: anggota tanpa berkas tetap tersimpan.
 */
class KkAnggotaDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        Storage::fake(PendudukDocumentService::DISK);
    }

    /**
     * Bangun TemporaryUploadedFile yang benar-benar berisi byte.
     *
     * Konstruktor TemporaryUploadedFile menambahkan prefix direktori
     * livewire-tmp/ pada path, jadi file harus ditulis DI DALAM prefix
     * tersebut sementara argumennya memakai nama telanjang.
     */
    private function tempFile(string $name, string $contents): TemporaryUploadedFile
    {
        $disk = FileUploadConfiguration::disk();

        Storage::fake($disk);

        $bare = 'test-'.$name;

        Storage::disk($disk)->put(
            FileUploadConfiguration::directory().'/'.$bare,
            $contents,
        );

        return new TemporaryUploadedFile($bare, $disk);
    }

    private function jpegBytes(): string
    {
        return "\xFF\xD8\xFF".str_repeat('a', 64);
    }

    private function pdfBytes(): string
    {
        return '%PDF-'.str_repeat('b', 64);
    }

    public function test_ktp_and_akta_are_stored_as_penduduk_documents(): void
    {
        $kk = KartuKeluarga::factory()->create([
            'rt_id' => Rt::factory()->create()->id,
        ]);

        $penduduk = Penduduk::factory()->create([
            'kk_id' => $kk->id,
        ]);

        $service = app(PendudukDocumentService::class);

        $service->store(
            $penduduk,
            $this->tempFile('ktp.jpg', $this->jpegBytes()),
            'KTP',
            null,
        );

        $service->store(
            $penduduk,
            $this->tempFile('akta.pdf', $this->pdfBytes()),
            'AKTA_KELAHIRAN',
            null,
        );

        $documents = PendudukDocument::query()
            ->where('penduduk_id', $penduduk->id)
            ->get();

        $this->assertCount(2, $documents);

        $ktp = $documents->firstWhere('document_type', 'KTP');
        $akta = $documents->firstWhere('document_type', 'AKTA_KELAHIRAN');

        $this->assertNotNull($ktp);
        $this->assertNotNull($akta);

        $this->assertSame('image/jpeg', $ktp->mime_type);
        $this->assertSame('application/pdf', $akta->mime_type);

        // File benar-benar ada di disk milik service.
        Storage::disk(PendudukDocumentService::DISK)
            ->assertExists($ktp->storage_path);

        Storage::disk(PendudukDocumentService::DISK)
            ->assertExists($akta->storage_path);
    }

    /**
     * Anggota tanpa dokumen tetap valid — keduanya opsional.
     */
    public function test_member_without_documents_has_no_document_rows(): void
    {
        $kk = KartuKeluarga::factory()->create([
            'rt_id' => Rt::factory()->create()->id,
        ]);

        $penduduk = Penduduk::factory()->create([
            'kk_id' => $kk->id,
        ]);

        $this->assertSame(
            0,
            PendudukDocument::query()
                ->where('penduduk_id', $penduduk->id)
                ->count(),
        );
    }

    /**
     * Tidak ada kolom KTP / Akta pada tabel penduduk — dokumen hanya
     * hidup di penduduk_documents dan terhubung lewat penduduk_id.
     */
    public function test_penduduk_table_has_no_document_columns(): void
    {
        $columns = \Schema::getColumnListing('penduduk');

        $this->assertNotContains('ktp_document', $columns);
        $this->assertNotContains('akta_kelahiran_document', $columns);
    }
}
