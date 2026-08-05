<?php

namespace App\Enums;

enum OcrJobStatus: string
{
    case PENDING = 'PENDING';
    case SUCCESS = 'SUCCESS';
    case LOW_CONFIDENCE = 'LOW_CONFIDENCE';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
