| Field | Value |
|---|---|
| **Title** | SIPETA Laravel 12 Conventions |
| **Purpose** | Laravel-specific patterns and conventions applied to SIPETA. |
| **Scope** | Routes, controllers, requests, services, models, queues, events, mail. |
| **Version** | 1.0.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/architecture.md`, `.ai/coding.md`, `.ai/database.md`, `.ai/filament.md` |

---

# SIPETA Laravel 12 Conventions

## 1. Audience

Developers working on Laravel 12 backend code for SIPETA.

## 2. Laravel Stack

- Laravel 12 (latest stable).
- PHP 8.3+.
- MySQL 8.
- Filament 4 for admin UI.
- Laravel Excel for XLSX export.
- DomPDF for PDF export.
- Tesseract via `Process` facade for OCR.

## 3. Application Layers

```
HTTP Request
   ↓
Middleware
   ↓
Route
   ↓
Controller (thin)
   ↓
Form Request (validation)
   ↓
Service / Action
   ↓
Eloquent Model
   ↓
Database
```

## 4. Routes

### 4.1 Web Routes

Located in `routes/web.php`. Filament registers its own routes under `/admin`.

### 4.2 Custom Routes

Only for:

- Backup download.
- Restore upload.

```php
Route::post('/backup/download/{log}', [BackupDownloadController::class, 'show'])
    ->middleware('auth:admin');
```

## 5. Controllers

Controllers are thin. Example:

```php
class BackupDownloadController extends Controller
{
    public function show(BackupLog $log): StreamedResponse
    {
        $this->authorize('view', $log);

        return Storage::disk('backup')->download($log->filename);
    }
}
```

## 6. Form Requests

All validation in Form Requests:

```php
class StorePendudukRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'kk_id' => ['required', 'integer', 'exists:kartu_keluarga,id'],
            'nik' => ['required', 'string', 'size:16', 'regex:/^[0-9]{16}$/', 'unique:penduduk,nik'],
            'full_name' => ['required', 'string', 'max:150'],
            'birth_date' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            // ...
        ];
    }

    public function messages(): array
    {
        return [
            'nik.required' => 'NIK wajib diisi.',
            'nik.size' => 'NIK harus 16 digit.',
            'nik.unique' => 'NIK sudah terdaftar.',
            'birth_date.before_or_equal' => 'Tanggal lahir tidak valid.',
        ];
    }
}
```

## 7. Services

Services are the home of business logic. Constructor-injected dependencies.

```php
class PendudukService
{
    public function __construct(
        private readonly PendudukRepository $repository,
    ) {}

    public function create(StorePendudukRequest $request): Penduduk
    {
        return DB::transaction(function () use ($request) {
            return $this->repository->create($request->validated());
        });
    }
}
```

Service responsibilities:

- Coordinated multi-table writes.
- Status transitions.
- Data integrity checks.
- Side effects (logs, events).

Services must NOT:

- Handle HTTP requests.
- Validate input (that's the Form Request).
- Render views.

## 8. Actions

Stateless single-purpose classes:

```php
class CreatePendudukAction
{
    public function execute(array $data): Penduduk
    {
        // ...
    }
}
```

Used for:

- One-shot jobs that may be dispatched from multiple places.
- Operations that need to be queued (future).

## 9. Models

### 9.1 Base Conventions

- Use `$fillable` or `$guarded`.
- Use `$casts` for date, enum, JSON.
- Use `$hidden` for sensitive fields.
- Define relationships, scopes, accessors.

### 9.2 Contoh Penduduk Model

```php
class Penduduk extends Model
{
    use HasFactory;

    protected $fillable = [
        'kk_id', 'nik', 'full_name', 'gender', 'birth_place', 'birth_date',
        'religion', 'education', 'occupation', 'marital_status',
        'family_relation', 'resident_status',
        'moved_at', 'moved_note', 'deceased_at', 'deceased_note', 'notes',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'moved_at' => 'date',
        'deceased_at' => 'date',
        'gender' => Gender::class,
        'religion' => Religion::class,
        'education' => Education::class,
        'marital_status' => MaritalStatus::class,
        'family_relation' => FamilyRelation::class,
        'resident_status' => ResidentStatus::class,
    ];

    public function kartuKeluarga(): BelongsTo
    {
        return $this->belongsTo(KartuKeluarga::class, 'kk_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('resident_status', ResidentStatus::ACTIVE);
    }

    public function getAgeAttribute(): int
    {
        return Carbon::parse($this->birth_date)->age;
    }
}
```

## 10. Repositories

Used only when queries become complex. Each Repository must justify itself in `.ai/decisions.md`.

## 11. Enums

```php
enum ResidentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case MOVED = 'MOVED';
    case DECEASED = 'DECEASED';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Aktif',
            self::MOVED => 'Pindah',
            self::DECEASED => 'Meninggal',
        };
    }
}
```

## 12. Database

### 12.1 Migrations

Filenames: `YYYY_MM_DD_HHMMSS_description.php`.

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penduduk', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('kk_id');
            $table->string('nik', 16)->unique();
            $table->string('full_name', 150);
            // ...
            $table->timestamps();

            $table->foreign('kk_id')
                ->references('id')->on('kartu_keluarga')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->index('full_name');
            $table->index('resident_status');
            $table->index('occupation');
            $table->index('birth_date');
            $table->index('gender');
            $table->index(['kk_id', 'resident_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penduduk');
    }
};
```

### 12.2 Factories

One factory per model. Use factories in tests.

### 12.3 Seeders

- `AdminSeeder` — creates the single admin user.
- Optionally `DemoDataSeeder` for development.

## 13. Storage

Laravel Storage driver configuration:

```php
// config/filesystems.php
'disks' => [
    'kk' => [
        'driver' => 'local',
        'root' => storage_path('app/kk'),
        'url' => env('APP_URL').'/storage/kk',
    ],
    'backup' => [
        'driver' => 'local',
        'root' => env('SIPETA_BACKUP_PATH', storage_path('app/backup')),
    ],
];
```

Store only the filename in the DB. The disk root is computed at runtime.

## 14. OCR

OCR is invoked via the `Process` facade:

```php
$result = Process::path(config('ocr.tesseract_path'))
    ->input($imagePath)
    ->run("tesseract \$input stdout -l ind --psm 6 tsv");
```

Wrap in `OCRService` — never call directly from Controllers.

## 15. Export

### 15.1 PDF

Use `barryvdh/laravel-dompdf`:

```php
use Barryvdh\DomPDF\Facade\Pdf;

Pdf::loadView('exports.penduduk', ['rows' => $rows])
    ->setPaper('a4', 'landscape')
    ->stream('penduduk.pdf');
```

### 15.2 Excel

Use `maatwebsite/excel`:

```php
class PendudukExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    public function query()
    {
        return Penduduk::query()->filter($this->filters);
    }

    public function headings(): array
    {
        return ['Nama', 'NIK', 'Umur', 'RT', 'Lingkungan', 'Status'];
    }

    public function map($row): array
    {
        return [
            $row->full_name,
            $row->nik,
            $row->age,
            $row->kartuKeluarga->rt,
            $row->kartuKeluarga->lingkungan,
            $row->resident_status->label(),
        ];
    }
}
```

### 15.3 CSV

Use `maatwebsite/excel` with `FromQuery` + `CsvExport`.

## 16. Backup

Backup is implemented in `BackupService`:

```php
class BackupService
{
    public function create(): BackupLog
    {
        $log = BackupLog::create([... 'started_at' => now()]);

        try {
            $filename = "backup_" . now()->format('Y-m-d_His') . ".zip";
            $path = Storage::disk('backup')->path($filename);

            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE);

            // Add SQL dump
            $sqlPath = $this->dumpDatabase();
            $zip->addFile($sqlPath, 'database.sql');

            // Add photos
            foreach (Storage::disk('kk')->files() as $file) {
                $zip->addFile(Storage::disk('kk')->path($file), "kk/{$file}");
            }

            $zip->close();

            $log->update([
                'backup_status' => 'SUCCESS',
                'backup_size' => filesize($path),
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'backup_status' => 'FAILED',
                'message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            throw $e;
        }

        return $log;
    }
}
```

## 17. Restore

Restore unzips into a temp folder, validates, then applies:

```php
class RestoreService
{
    public function execute(UploadedFile $file): void
    {
        // 1. Validate ZIP
        // 2. Extract to temp
        // 3. Validate SQL dump
        // 4. Replace database
        // 5. Replace photos
        // 6. Log action
    }
}
```

## 18. Error Handling

- Global exception handler in `bootstrap/app.php`.
- Custom render for `DomainException` → friendly message.
- Stack traces are logged, never displayed.

## 19. Performance

- Eager-load relationships.
- Index all searchable columns (see `.ai/database.md`).
- Cache dashboard counts (5-min TTL).

## 20. Queues

- Not used in KKN scope.
- OCR is synchronous.
- Backup is synchronous.

## 21. Testing

- `php artisan test` for unit and feature tests.
- PHPUnit.
- SQLite `:memory:` for tests.

## 22. Implementation Notes

- Strict types declared in `phpunit.xml` and `composer.json`.
- PHPStan level 5.
- Pint for code style.

## 23. Future Improvements

- Move to Octane for performance.
- Add queues for OCR.
- Add a `sipeta-cli` artisan command set.
