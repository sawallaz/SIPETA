<?php

namespace Tests\Feature\Phase3;

use App\Models\Penduduk;
use App\Models\PendudukDocument;
use App\Services\PendudukDocumentService;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Dokumen pendukung Penduduk (KTP, Akta Kelahiran).
 *
 * Aturan yang diuji:
 * - dokumen bersifat opsional (tanpa upload Penduduk tetap tersimpan).
 * - ganti dokumen -> dokumen lama diarsipkan (is_active=false, file tetap ada).
 * - hapus Penduduk -> dokumen + file fisik ikut dihapus (via model deleting).
 */
class PendudukDocumentServiceTest extends Phase3ResourceTestCase
{
    private function makeUploadedFile(
        string $name,
        string $contents,
    ): TemporaryUploadedFile {
        Storage::fake(FileUploadConfiguration::disk());

        $path = 'test-'.$name;
        Storage::disk(FileUploadConfiguration::disk())
            ->put(
                FileUploadConfiguration::directory().'/'.$path,
                $contents,
            );

        return new TemporaryUploadedFile($path, FileUploadConfiguration::disk());
    }

    public function test_store_creates_a_single_active_document(): void
    {
        Storage::fake(PendudukDocumentService::DISK);

        $penduduk = Penduduk::factory()->create();
        $file = $this->makeUploadedFile(
            'ktp.jpg',
            "\xFF\xD8\xFF\xE0dummy-jpg",
        );

        $doc = app(PendudukDocumentService::class)
            ->store($penduduk, $file, 'KTP', $this->admin->id);

        $this->assertSame(1, $penduduk->documents()->count());
        $this->assertTrue($doc->is_active);
        $this->assertSame('KTP', $doc->document_type);
        $this->assertSame($this->admin->id, $doc->uploaded_by);

        Storage::disk(PendudukDocumentService::DISK)
            ->assertExists($doc->storage_path);
    }

    public function test_replacing_document_archives_old_version(): void
    {
        Storage::fake(PendudukDocumentService::DISK);

        $penduduk = Penduduk::factory()->create();

        $first = app(PendudukDocumentService::class)
            ->store(
                $penduduk,
                $this->makeUploadedFile('ktp-1.jpg', "\xFF\xD8\xFF\xE0first"),
                'KTP',
                $this->admin->id,
            );

        $second = app(PendudukDocumentService::class)
            ->store(
                $penduduk,
                $this->makeUploadedFile('ktp-2.jpg', "\xFF\xD8\xFF\xE0second"),
                'KTP',
                $this->admin->id,
            );

        // Kedua record tetap ada sebagai riwayat.
        $this->assertSame(
            2,
            PendudukDocument::where('penduduk_id', $penduduk->id)->count(),
        );

        // Yang lama diarsipkan, yang baru aktif.
        $this->assertFalse(
            $first->fresh()->is_active,
            'Dokumen lama harus diarsipkan (is_active=false).',
        );
        $this->assertTrue(
            $second->fresh()->is_active,
            'Dokumen baru harus aktif.',
        );

        // Tepat satu dokumen aktif per tipe.
        $this->assertSame(
            1,
            PendudukDocument::where('penduduk_id', $penduduk->id)
                ->where('is_active', true)
                ->count(),
        );

        // File lama tetap ada di disk.
        Storage::disk(PendudukDocumentService::DISK)
            ->assertExists($first->fresh()->storage_path);
    }

    public function test_different_document_types_do_not_clash(): void
    {
        Storage::fake(PendudukDocumentService::DISK);

        $penduduk = Penduduk::factory()->create();

        app(PendudukDocumentService::class)
            ->store(
                $penduduk,
                $this->makeUploadedFile('ktp.jpg', "\xFF\xD8\xFF\xE0ktp"),
                'KTP',
                $this->admin->id,
            );

        app(PendudukDocumentService::class)
            ->store(
                $penduduk,
                $this->makeUploadedFile('akta.pdf', '%PDF-dummy'),
                'AKTA_KELAHIRAN',
                $this->admin->id,
            );

        $this->assertSame(2, $penduduk->documents()->count());
        $this->assertSame(
            1,
            PendudukDocument::where('penduduk_id', $penduduk->id)
                ->where('document_type', 'KTP')
                ->where('is_active', true)
                ->count(),
        );
        $this->assertSame(
            1,
            PendudukDocument::where('penduduk_id', $penduduk->id)
                ->where('document_type', 'AKTA_KELAHIRAN')
                ->where('is_active', true)
                ->count(),
        );
    }

    public function test_deleting_penduduk_removes_documents_and_files(): void
    {
        Storage::fake(PendudukDocumentService::DISK);

        $penduduk = Penduduk::factory()->create();

        $doc = app(PendudukDocumentService::class)
            ->store(
                $penduduk,
                $this->makeUploadedFile('ktp.jpg', "\xFF\xD8\xFF\xE0ktp"),
                'KTP',
                $this->admin->id,
            );

        $this->assertTrue(
            Storage::disk(PendudukDocumentService::DISK)
                ->exists($doc->storage_path),
        );

        // Penduduk::deleting harus memanggil deleteForPenduduk().
        $penduduk->delete();

        $this->assertSame(
            0,
            PendudukDocument::where('penduduk_id', $penduduk->id)->count(),
        );
        $this->assertFalse(
            Storage::disk(PendudukDocumentService::DISK)
                ->exists($doc->storage_path),
            'File fisik dokumen harus ikut terhapus.',
        );
    }

    public function test_unsupported_document_type_throws(): void
    {
        Storage::fake(PendudukDocumentService::DISK);

        $penduduk = Penduduk::factory()->create();

        $this->expectException(\RuntimeException::class);

        app(PendudukDocumentService::class)
            ->store(
                $penduduk,
                $this->makeUploadedFile('x.jpg', "\xFF\xD8\xFF\xE0x"),
                'PASSPOR',
                $this->admin->id,
            );
    }
}
