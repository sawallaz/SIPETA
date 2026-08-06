<?php

namespace App\Enums;

enum OcrJobStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case SUCCESS = 'SUCCESS';
    case LOW_CONFIDENCE = 'LOW_CONFIDENCE';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
    case COMPLETED = 'COMPLETED';

    /**
     * Statuses the ocr_jobs.status column can currently store.
     *
     * PROCESSING is excluded: the Phase 2 enum constraint (SQLite CHECK /
     * MySQL ENUM) predates it, so it exists only as an in-memory runtime
     * state until that constraint is widened in a future schema change.
     *
     * COMPLETED is the terminal state reached by the finalization stage
     * (Phase 5.9): its value was added to the column constraint by the
     * `2026_08_07_..._add_completed_status_to_ocr_jobs_table` migration, so
     * it IS persistable here.
     *
     * @return array<int, OcrJobStatus>
     */
    public static function persistable(): array
    {
        return [
            self::PENDING,
            self::SUCCESS,
            self::LOW_CONFIDENCE,
            self::FAILED,
            self::CANCELLED,
            self::COMPLETED,
        ];
    }
}
