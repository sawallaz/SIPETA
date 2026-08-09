<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Penduduk Domain Configuration (.ai/hermes.md §11, §14)
    |--------------------------------------------------------------------------
    |
    | Age is NEVER stored (ADR-007); only birth_date is. Presets below
    | translate to a birth_date span at query time so filtering never
    | computes a stored value. Ranges are contiguous — every resident
    | falls into exactly one preset:
    |
    |   balita  (0–5)   anak (6–12)   remaja (13–17)
    |   dewasa  (18–59) lansia (60+)
    |
    */

    // Age preset ranges in years. `max` = null means "no upper bound".
    'age_presets' => [
        'balita' => ['label' => 'Balita', 'min' => 0, 'max' => 5],
        'anak' => ['label' => 'Anak', 'min' => 6, 'max' => 12],
        'remaja' => ['label' => 'Remaja', 'min' => 13, 'max' => 17],
        'dewasa' => ['label' => 'Dewasa', 'min' => 18, 'max' => 59],
        'lansia' => ['label' => 'Lansia', 'min' => 60, 'max' => null],
    ],

];
