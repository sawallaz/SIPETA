<?php

namespace App\Enums;

enum MaritalStatus: string
{
    case BELUM_KAWIN = 'BELUM_KAWIN';
    case KAWIN = 'KAWIN';
    case CERAI_HIDUP = 'CERAI_HIDUP';
    case CERAI_MATI = 'CERAI_MATI';
}
