<?php

namespace App\Enums;

enum BackupStatus: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case SUCCESS = 'SUCCESS';
    case FAILED = 'FAILED';
}
