<?php

namespace App\Enums;

enum OcrOutcome: string
{
    case SAVED = 'SAVED';
    case DISCARDED = 'DISCARDED';
    case MANUAL = 'MANUAL';
}
