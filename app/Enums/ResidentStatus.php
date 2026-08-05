<?php

namespace App\Enums;

enum ResidentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case PINDAH = 'PINDAH';
    case MENINGGAL = 'MENINGGAL';
}
