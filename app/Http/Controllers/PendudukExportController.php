<?php

namespace App\Http\Controllers;

use App\Enums\ExportFormat;
use App\Services\PendudukExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PendudukExportController
{
    public function pdf(
        Request $request,
        PendudukExportService $exportService,
    ): Response {
        $rawFilters = $request->query('filters', []);
        $search = trim((string) $request->query('search', ''));

        // Normalisasi state filter Filament -> skalar yang dipahami service.
        $f = $this->normalizeFilters($rawFilters);

        // Kunci yang sudah ditangani applyFilters() di service.
        $serviceFilters = collect($f)->only([
            'rt',
            'area_unit',
            'gender',
            'religion_id',
            'education_id',
            'occupation_id',
            'resident_status',
        ])->all();

        // Bangun query yang secara logis sama dengan tabel Penduduk.
        $query = $exportService->buildQuery($serviceFilters);

        // Search utama tabel: NIK / Nama / Nomor KK.
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('nik', 'like', "%{$search}%")
                    ->orWhere('full_name', 'like', "%{$search}%")
                    ->orWhereHas(
                        'kartuKeluarga',
                        fn (Builder $kk): Builder => $kk
                            ->where('kk_number', 'like', "%{$search}%")
                    );
            });
        }

        // Filter teks: Nama / NIK / Nomor KK.
        if (filled($f['nama'] ?? null)) {
            $query->where('full_name', 'like', '%'.$f['nama'].'%');
        }

        if (filled($f['nik'] ?? null)) {
            $query->where('nik', 'like', '%'.$f['nik'].'%');
        }

        if (filled($f['kk_number'] ?? null)) {
            $query->whereHas(
                'kartuKeluarga',
                fn (Builder $kk): Builder => $kk->where(
                    'kk_number',
                    'like',
                    '%'.$f['kk_number'].'%'
                )
            );
        }

        // Usia preset (config penduduk.age_presets -> birth_date span).
        if (filled($f['age_preset'] ?? null)) {
            $preset = config("penduduk.age_presets.{$f['age_preset']}", []);

            if (is_array($preset) && $preset !== []) {
                $query->ageRange(
                    $preset['min'] ?? null,
                    $preset['max'] ?? null
                );
            }
        }

        // Usia kustom (min / max).
        $age = $f['age'] ?? null;
        $ageMin = null;
        $ageMax = null;

        if (is_array($age)) {
            $ageMin = $age['min'] ?? null;
            $ageMax = $age['max'] ?? null;

            if (filled($ageMin) || filled($ageMax)) {
                $query->ageRange(
                    filled($ageMin) ? (int) $ageMin : null,
                    filled($ageMax) ? (int) $ageMax : null,
                );
            }
        }

        // Filter untuk ringkasan nama file (tanpa 'age' array mentah).
        $summaryFilters = $serviceFilters;

        if (filled($ageMin)) {
            $summaryFilters['age_min'] = $ageMin;
        }

        if (filled($ageMax)) {
            $summaryFilters['age_max'] = $ageMax;
        }

        return $exportService->exportQuery(
            $query,
            ExportFormat::PDF,
            $summaryFilters,
        );
    }

    /**
     * Filament menyimpan filter dengan bentuk state berbeda per jenis:
     *
     *   - Filter teks (nama/nik/kk_number) => ['query' => '...']
     *   - SelectFilter (area_unit/rt/gender/.../age_preset) => ['value' => ...]
     *   - Filter usia kustom (age) => ['min' => ..., 'max' => ...]
     *
     * Dinormalisasi menjadi skalar (atau array min/max untuk age) agar
     * service dan query manual di atas konsisten.
     */
    private function normalizeFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        $textKeys = ['nama', 'nik', 'kk_number'];
        $valueKeys = [
            'area_unit',
            'rt',
            'gender',
            'religion_id',
            'education_id',
            'occupation_id',
            'resident_status',
            'age_preset',
        ];

        $normalized = [];

        foreach ($filters as $key => $data) {
            if (! is_array($data)) {
                $normalized[$key] = $data;

                continue;
            }

            if (in_array($key, $textKeys, true)) {
                $normalized[$key] = $data['query'] ?? null;

                continue;
            }

            if (in_array($key, $valueKeys, true)) {
                $normalized[$key] = $data['value'] ?? null;

                continue;
            }

            if ($key === 'age') {
                $normalized[$key] = $data;

                continue;
            }

            // Fallback: ambil 'value' dulu, lalu 'query'.
            $normalized[$key] = $data['value']
                ?? ($data['query'] ?? null);
        }

        return $normalized;
    }
}
