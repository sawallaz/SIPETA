<?php

namespace App\Services;

use App\Enums\OcrJobStatus;
use App\Models\OcrJob;
use App\Models\User;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * OCR upload foundation (Phase 5.1).
 *
 * Validates an uploaded KK document (scan/photo), stores it on the private
 * `kk_uploads` disk, and registers a PENDING `ocr_jobs` row. OCR extraction
 * itself is a later sub-phase — this service only accepts, validates, and
 * records uploads. On validation failure nothing is persisted: no file is
 * stored and no job row is created.
 */
class KkDocumentUploadService
{
    /** Private local disk holding uploaded KK documents. */
    public const DISK = 'kk_uploads';

    /** Maximum accepted upload size in kilobytes (25 MB). */
    public const MAX_SIZE_KB = 25600;

    /** Accepted file extensions (.ai/ocr.md §4.1: JPG, JPEG, PNG). */
    public const ALLOWED_EXTENSIONS = 'jpg,jpeg,png';

    /** Accepted MIME types (NFR-SEC-05: validated by MIME type and size). */
    public const ALLOWED_MIMES = 'image/jpeg,image/png';

    public function __construct(private readonly FilesystemManager $filesystem) {}

    /**
     * Validation rules for a single file input named "document".
     * Reusable by a future controller/FormRequest when the upload UI lands.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'document' => [
                'required',
                'file',
                'mimes:'.self::ALLOWED_EXTENSIONS,
                'mimetypes:'.self::ALLOWED_MIMES,
                'max:'.self::MAX_SIZE_KB,
            ],
        ];
    }

    /**
     * Validate the file without persisting anything.
     *
     * @throws ValidationException
     */
    public function validate(UploadedFile $file): void
    {
        $validator = Validator::make(['document' => $file], static::rules());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Accept a KK document upload: validate, store on the secure disk, and
     * register a PENDING OCR job.
     *
     * @throws ValidationException when the file fails validation
     * @throws RuntimeException when the file cannot be stored
     */
    public function upload(UploadedFile $file, ?User $operator = null): OcrJob
    {
        $this->validate($file);

        $hash = hash_file('sha256', (string) $file->getRealPath());
        $storedFilename = Str::uuid().'.'.strtolower((string) $file->getClientOriginalExtension());

        $storedPath = $this->filesystem->disk(self::DISK)->putFileAs('', $file, $storedFilename);

        if ($storedPath === false) {
            throw new RuntimeException('Failed to store the uploaded KK document.');
        }

        return OcrJob::create([
            'kk_id' => null,
            'source_image_hash' => $hash,
            'source_image_path' => $storedPath,
            'status' => OcrJobStatus::PENDING,
            'operator_id' => $operator?->id,
            'started_at' => now(),
        ]);
    }
}
