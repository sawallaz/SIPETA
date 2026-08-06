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

    /**
     * Statuses the ocr_jobs.status column can currently store.
     *
     * PROCESSING is excluded: the Phase 2 enum constraint (SQLite CHECK /
     * MySQL ENUM) predates it, so it exists only as an in-memory runtime
     * state until that constraint is widened in a future schema change.
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
        ];
    }
}
