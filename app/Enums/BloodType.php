<?php

namespace App\Enums;

enum BloodType: string
{
    case A = 'A';
    case B = 'B';
    case AB = 'AB';
    case O = 'O';
    case TIDAK_DIKETAHUI = 'TIDAK_DIKETAHUI';
}
