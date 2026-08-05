<?php

namespace App\Enums;

enum FamilyRelation: string
{
    case KEPALA_KELUARGA = 'KEPALA_KELUARGA';
    case ISTRI = 'ISTRI';
    case ANAK = 'ANAK';
    case MENANTU = 'MENANTU';
    case CUCU = 'CUCU';
    case ORANG_TUA = 'ORANG_TUA';
    case MERTUA = 'MERTUA';
    case FAMILI_LAIN = 'FAMILI_LAIN';
    case LAINNYA = 'LAINNYA';
}
