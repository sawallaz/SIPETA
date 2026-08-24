<?php

namespace App\Http\Controllers;

use App\Services\PendudukImportService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PendudukImportController extends Controller
{
    private const ALLOWED_EXTENSIONS = ['xlsx', 'csv'];

    private const ALLOWED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'application/csv',
        'text/plain',
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private readonly PendudukImportService $importService
    ) {}

    /**
     * Upload file Excel/CSV untuk import penduduk.
     * File disimpan sementara, kemudian di-parse.
     */
    public function upload(Request $request, ?UploadedFile $uploadedFile = null): array
    {
        $file = $uploadedFile ?? $request->file('file');
        $validator = Validator::make(['file' => $file], [
            'file' => [
                'required',
                'file',
                'max:'.(self::MAX_FILE_SIZE / 1024), // dalam KB
            ],
        ]);

        if ($validator->fails()) {
            return ['error' => $validator->errors()->first()];
        }

        $extension = strtolower($file->getClientOriginalExtension() ?? '');

        // Validasi extension
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            if ($extension === 'xls') {
                return ['error' => 'Format .xls belum didukung. Gunakan .xlsx atau .csv.'];
            }

            return ['error' => 'Format file tidak didukung. Hanya .xlsx dan .csv yang diterima.'];
        }

        // Validasi MIME type
        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            return ['error' => 'Tipe file tidak valid. Periksa kembali file Anda.'];
        }

        // Validasi ukuran
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return ['error' => 'Ukuran file melebihi batas maksimal (10 MB).'];
        }

        // Simpan ke temporary storage
        $filename = 'penduduk_import_'.Str::uuid().'.'.$extension;
        $path = $file->storeAs('temp/penduduk_import', $filename, 'local');

        if ($path === false) {
            return ['error' => 'Gagal menyimpan file sementara.'];

        }

        // Parse file
        $result = $this->importService->parseFile(Storage::disk('local')->path($path));

        if (isset($result['error'])) {
            // Cleanup kalau gagal parse
            Storage::disk('local')->delete($path);

            return $result;
        }

        // Simpan metadata ke session untuk langkah selanjutnya
        $request->session()->put('penduduk_import', [
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'sheets' => $result['sheets'] ?? [],
            'sheet_index' => 0,
            'headers' => [],
            'rows' => [],
            'total_rows' => 0,
            'parsed' => false,
        ]);

        return [
            'success' => true,
            'sheets' => $result['sheets'],
            'file_path' => $path,
            'message' => 'File berhasil diupload. Pilih sheet untuk dilanjutkan.',
        ];
    }

    /**
     * Pilih sheet dari file Excel (jika multi-sheet).
     */
    public function selectSheet(Request $request, ?int $selectedSheetIndex = null): array
    {
        $session = $request->session()->get('penduduk_import');
        if (! $session || ! isset($session['file_path'])) {
            return ['error' => 'Tidak ada file yang diupload. Upload file terlebih dahulu.'];
        }

        $sheetIndex = $selectedSheetIndex ?? (int) $request->input('sheet_index', 0);
        $sheets = $session['sheets'] ?? [];

        if (! isset($sheets[$sheetIndex])) {
            return ['error' => 'Sheet tidak valid.'];
        }

        // Parse sheet yang dipilih
        $result = $this->importService->parseSheet(
            Storage::disk('local')->path($session['file_path']),
            $sheets[$sheetIndex]
        );

        if (isset($result['error'])) {
            return $result;
        }

        // Update session
        $request->session()->put('penduduk_import', array_merge($session, [
            'sheet_index' => $sheetIndex,
            'sheet_name' => $sheets[$sheetIndex],
            'headers' => $result['headers'] ?? [],
            'rows' => $result['rows'] ?? [],
            'total_rows' => $result['total_rows'] ?? 0,
            'parsed' => true,
        ]));

        return [
            'success' => true,
            'sheet_name' => $sheets[$sheetIndex],
            'headers' => $result['headers'],
            'rows' => $result['rows'],
            'total_rows' => $result['total_rows'],
            'preview_rows' => array_slice($result['rows'] ?? [], 0, 10),
        ];
    }

    /**
     * Mapping kolom otomatis + preview.
     */
    public function mapColumns(Request $request): array
    {
        $session = $request->session()->get('penduduk_import');
        if (! $session || empty($session['headers'])) {
            return ['error' => 'Data belum di-parse. Pilih sheet terlebih dahulu.'];
        }

        $headers = $session['headers'];
        $mapping = $this->importService->suggestMapping($headers);

        // Simpan mapping ke session
        $request->session()->put('penduduk_import.mapping', $mapping);

        return [
            'success' => true,
            'headers' => $headers,
            'mapping' => $mapping['mapping'] ?? [],
            'ambiguous' => $mapping['ambiguous'] ?? [],
            'missing_required' => $mapping['missing_required'] ?? [],
            'unrecognized' => $mapping['unrecognized'] ?? [],
        ];
    }

    /**
     * Validasi dan preview sebelum import.
     */
    public function preview(Request $request): array
    {
        $session = $request->session()->get('penduduk_import');
        if (! $session || empty($session['rows'])) {
            return ['error' => 'Data belum siap. Lakukan mapping terlebih dahulu.'];
        }

        $mapping = $request->session()->get('penduduk_import.mapping', []);
        $rows = $session['rows'];
        $totalRows = count($rows);

        // Validasi semua row
        $validationResult = $this->importService->validateRows(
            $rows,
            $mapping['mapping'] ?? [],
            $mapping['custom_mapping'] ?? []
        );

        // Simpan hasil validasi
        $request->session()->put('penduduk_import.validation', $validationResult);

        return [
            'success' => true,
            'total' => $totalRows,
            'valid' => $validationResult['valid_count'],
            'duplicate' => $validationResult['duplicate_count'],
            'invalid' => $validationResult['invalid_count'],
            'preview_rows' => $validationResult['preview_rows'] ?? [],
            'errors' => $validationResult['errors'] ?? [],
        ];
    }

    /**
     * Eksekusi import.
     */
    public function import(Request $request): array
    {
        $session = $request->session()->get('penduduk_import');
        if (! $session || empty($session['rows'])) {
            return ['error' => 'Data tidak tersedia.'];
        }

        $mapping = $request->session()->get('penduduk_import.mapping', []);
        $validation = $request->session()->get('penduduk_import.validation', []);

        // Validasi ulang sebelum import
        if (empty($validation) || ! isset($validation['valid_rows'])) {
            return ['error' => 'Validasi belum dilakukan.'];
        }

        $validRows = $validation['valid_rows'] ?? [];
        $mappingFinal = $mapping['mapping'] ?? [];
        $customMapping = $mapping['custom_mapping'] ?? [];

        // Eksekusi import (transactional)
        try {
            $result = $this->importService->importRows($validRows, $request->user());

            // Cleanup file temporary
            if (isset($session['file_path'])) {
                Storage::disk('local')->delete($session['file_path']);
            }

            // Hapus session
            $request->session()->forget('penduduk_import');

            $duplicateCount = (int) ($validation['duplicate_count'] ?? 0) + (int) ($result['duplicates'] ?? 0);
            $invalidCount = (int) ($validation['invalid_count'] ?? 0) + (int) ($result['invalids'] ?? 0);

            return [
                'success' => true,
                'status' => $result['status'],
                'total_imported' => $result['imported'],
                'total_skipped' => $duplicateCount + $invalidCount,
                'duplicate_count' => $duplicateCount,
                'invalid_count' => $invalidCount,
                'message' => $result['message'] ?? 'Import selesai.',
                'details' => $result['details'] ?? [],
            ];
        } catch (\Throwable $e) {
            // Cleanup kalau gagal
            if (isset($session['file_path'])) {
                Storage::disk('local')->delete($session['file_path']);
            }
            $request->session()->forget('penduduk_import');

            return [
                'success' => false,
                'error' => 'Gagal melakukan import: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Batalkan import dan bersihkan temporary file.
     */
    public function cancel(Request $request): array
    {
        $session = $request->session()->get('penduduk_import');

        if ($session && isset($session['file_path'])) {
            Storage::disk('local')->delete($session['file_path']);
        }

        $request->session()->forget('penduduk_import');

        return ['success' => true, 'message' => 'Import dibatalkan. File temporary telah dihapus.'];
    }

    /**
     * Hapus session import (untuk logout / cleanup).
     */
    public function clearSession(Request $request): array
    {
        $session = $request->session()->get('penduduk_import');

        if ($session && isset($session['file_path'])) {
            Storage::disk('local')->delete($session['file_path']);
        }

        $request->session()->forget('penduduk_import');

        return ['success' => true];
    }
}
