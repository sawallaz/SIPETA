<?php

namespace App\Filament\Resources\OcrJobs;

use App\Filament\Resources\OcrJobs\Pages\ListOcrJobs;
use App\Filament\Resources\OcrJobs\Pages\ReviewOcrJob;
use App\Models\OcrJob;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * OCR jobs — the review workflow entry point (Phase 5.6).
 *
 * Index page lists finished jobs; the Review page lets the operator inspect,
 * correct and validate parsed OCR data before any import. Nothing is written
 * to the database (ADR-009: OCR is an assistant).
 */
class OcrJobResource extends Resource
{
    protected static ?string $model = OcrJob::class;

    protected static BackedEnum|string|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static UnitEnum|string|null $navigationGroup = 'Kependudukan';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Review OCR';

    protected static ?string $modelLabel = 'Job OCR';

    protected static ?string $pluralModelLabel = 'Job OCR';

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('status')->label('Status')->badge(),
                TextColumn::make('confidence')->label('Confidence')->sortable()
                    ->formatStateUsing(fn ($state) => $state === null ? '-' : round((float) $state, 1).'%'),
                TextColumn::make('started_at')->label('Mulai')->dateTime('d/m/Y H:i'),
                TextColumn::make('finished_at')->label('Selesai')->dateTime('d/m/Y H:i')->placeholder('-'),
            ])
            ->actions([
                Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-m-magnifying-glass')
                    ->url(fn (OcrJob $record): string => ReviewOcrJob::getUrl(['record' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOcrJobs::route('/'),
            'review' => ReviewOcrJob::route('/{record}/review'),
        ];
    }
}
