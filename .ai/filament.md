| Field | Value |
|---|---|
| **Title** | SIPETA Filament 4 Conventions |
| **Purpose** | Filament-specific patterns and conventions for SIPETA. |
| **Scope** | Resources, Pages, Forms, Tables, Filters, Widgets, Actions, Notifications. |
| **Version** | 1.0.0 |
| **Status** | Approved |
| **Last Updated** | 2026-08-03 |
| **Related Documents** | `.ai/hermes.md`, `.ai/architecture.md`, `.ai/coding.md`, `.ai/database.md`, `.ai/laravel.md` |

---

# SIPETA Filament 4 Conventions

## 1. Audience

Developers building the admin UI with Filament 4.

## 2. When to Use Filament

Use Filament for:

- CRUD resources.
- Forms (with validation).
- Tables (with filters, search, sort).
- Filters.
- Export.
- Widgets (dashboard cards and charts).

Avoid Filament plugins unless they are explicitly approved.

## 3. Folder Structure

```
app/Filament/
├── Resources/
│   ├── KartuKeluargaResource.php
│   ├── KartuKeluargaResource/
│   │   └── Pages/
│   │       ├── CreateKartuKeluarga.php
│   │       ├── EditKartuKeluarga.php
│   │       └── ListKartuKeluargas.php
│   ├── PendudukResource.php
│   └── PendudukResource/
│       └── Pages/
├── Pages/
│   ├── Dashboard.php
│   ├── Backup.php
│   ├── Restore.php
│   └── Pengaturan.php
└── Widgets/
    ├── StatsOverview.php
    ├── PendudukPerRTChart.php
    └── PendudukPerLingkunganChart.php
```

## 4. Resources

### 4.1 Contoh KartuKeluargaResource

```php
class KartuKeluargaResource extends Resource
{
    protected static ?string $model = KartuKeluarga::class;
    protected static ?string $navigationIcon = 'heroicon-o-identification';
    protected static ?string $navigationLabel = 'Kartu Keluarga';
    protected static ?string $modelLabel = 'Kartu Keluarga';
    protected static ?string $pluralModelLabel = 'Kartu Keluarga';
    protected static ?string $navigationGroup = 'Data Penduduk';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Informasi KK')->schema([
                TextInput::make('kk_number')->required()->length(16),
                TextInput::make('address')->required()->maxLength(255),
                TextInput::make('rt')->numeric()->required(),
                TextInput::make('rw')->numeric()->required(),
                TextInput::make('lingkungan')->required(),
                TextInput::make('postal_code')->maxLength(10),
            ]),
            Section::make('Foto KK')->schema([
                FileUpload::make('kk_photo_path')
                    ->image()
                    ->directory('kk')
                    ->maxSize(5120),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('kk_number')->searchable()->sortable(),
            TextColumn::make('address')->searchable(),
            TextColumn::make('rt')->sortable(),
            TextColumn::make('rw')->sortable(),
            TextColumn::make('lingkungan')->searchable(),
        ])
        ->filters([
            // (filters defined in .ai/workflow.md)
        ])
        ->actions([
            Tables\Actions\ViewAction::make(),
            Tables\Actions\EditAction::make(),
        ]);
    }
}
```

### 4.2 Resource Rules

- One Resource per Model.
- `form()` returns grouped `Section` schemas.
- `table()` returns columns + filters + actions.
- Use `relationship` columns where appropriate.
- Use enums for selects.

## 5. Forms

### 5.1 Field Rules

- Use the shortest label possible.
- Always pair with the Bahasa Indonesia label.
- Group related fields into `Section` blocks.
- Use `Tabs` only when content is genuinely long.

### 5.2 Validation

- Inline validation via Filament's validator.
- All messages in Bahasa Indonesia.
- Custom rules go into Form Requests invoked from custom Actions.

### 5.3 Field Components

| Field | Component |
|-------|-----------|
| Short text | `TextInput` |
| Long text | `Textarea` |
| Date | `DatePicker` |
| Enum select | `Select` |
| Boolean | `Toggle` |
| File | `FileUpload` |
| Image | `FileUpload` with `image()` |
| Read-only | `Placeholder` or `TextInput` with `disabled()` |

## 6. Tables

### 6.1 Columns

- Default: searchable and sortable.
- Use `BadgeColumn` for status.
- Use `ImageColumn` for KK photos.

### 6.2 Filters

Filters are visible on the page, not in a modal. Use:

- `SelectFilter`
- `Filter` (custom query)
- `TernaryFilter` for boolean-like fields

### 6.3 Actions

- `ViewAction`, `EditAction`, `DeleteAction` are standard.
- Custom actions for OCR, status change, photo upload.

### 6.4 Bulk Actions

- Avoid in KKN scope unless explicitly required.

## 7. Pages

### 7.1 Custom Pages

For Backup, Restore, Pengaturan — use `Filament\Pages\Page`.

```php
class Backup extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';
    protected static ?string $navigationLabel = 'Backup';
    protected static string $view = 'filament.pages.backup';
}
```

### 7.2 Page View

Use Blade + Livewire for interactivity. Avoid heavy JS.

## 8. Widgets

### 8.1 Stats Overview

```php
class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Penduduk Aktif', Penduduk::active()->count()),
            Stat::make('Total KK', KartuKeluarga::count()),
            // ...
        ];
    }
}
```

### 8.2 Charts

Use `ChartJs` widget or `ApexChartsWidget`.

## 9. Notifications

```php
Notification::make()
    ->title('Data berhasil disimpan')
    ->success()
    ->send();
```

## 10. Authorization

- Use `Policy` classes.
- `KartuKeluargaPolicy`, `PendudukPolicy`.
- `viewAny`, `view`, `create`, `update`, `delete`.
- For single-admin: all return `true`. The policy is for future-proofing.

## 11. Custom Actions

For OCR and other multi-step flows:

```php
Action::make('runOcr')
    ->label('Mulai OCR')
    ->icon('heroicon-o-camera')
    ->action(function (KartuKeluarga $record) {
        return app(OCRAction::class)->execute($record);
    });
```

## 12. Bahasa Indonesia

- All custom labels and notifications in Bahasa Indonesia.
- Use `__('strings.xxx')` where possible.

## 13. Performance

- Use `Pagination` to limit results.
- Use `->lazy()` for tables that grow large.
- Cache dashboard widgets.

## 14. Filament Conventions

- One Resource per Model.
- No business logic in Filament Resources.
- Use Services for logic.
- Use Actions for orchestration.

## 15. Implementation Notes

- Filament's `protected static ?string $navigationIcon` uses Heroicons.
- Filament's `Table` objects support `persistFiltersInSession` for filter persistence.
- Use `Filament\Forms\Components\Section` for grouping.

## 16. Future Improvements

- Add a `ficial` package for additional Bahasa Indonesia translations.
- Add `v2` upgrade path for Filament 4.
- Add custom theme if time permits.
