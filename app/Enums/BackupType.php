<?php

namespace App\Enums;

enum BackupType: string
{
    case MANUAL = 'MANUAL';
    case SCHEDULED = 'SCHEDULED';
}
