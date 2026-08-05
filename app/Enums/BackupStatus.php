<?php

namespace App\Enums;

enum BackupStatus: string
{
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
}
