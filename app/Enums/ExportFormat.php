<?php

namespace App\Enums;

/**
 * Supported reporting/export output formats (Phase 6.1 — Reporting & Export foundation).
 */
enum ExportFormat: string
{
    case PDF = 'pdf';
    case XLSX = 'xlsx';
    case CSV = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::PDF => 'PDF',
            self::XLSX => 'Excel (.xlsx)',
            self::CSV => 'CSV',
        };
    }

    public function mime(): string
    {
        return match ($this) {
            self::PDF => 'application/pdf',
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::CSV => 'text/csv',
        };
    }
}
