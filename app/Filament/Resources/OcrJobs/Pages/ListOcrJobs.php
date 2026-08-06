<?php

namespace App\Filament\Resources\OcrJobs\Pages;

use App\Filament\Resources\OcrJobs\OcrJobResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Lists OCR jobs for the operator to pick one for review (Phase 5.6).
 */
class ListOcrJobs extends ListRecords
{
    protected static string $resource = OcrJobResource::class;

    public function getTitle(): string
    {
        return 'Review OCR';
    }
}
