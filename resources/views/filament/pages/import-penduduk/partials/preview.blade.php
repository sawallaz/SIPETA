<div class="bg-white rounded-lg border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">4. Preview & Validasi Data</h2>

    @php
        $previewData = $page->previewData;
    @endphp

    @if ($previewData)
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-3">
            <div class="bg-white rounded-lg border border-gray-200 p-4 text-center dark:bg-gray-800 dark:border-gray-700">
                <div class="text-2xl font-bold text-gray-700 dark:text-gray-200">{{ $previewData['total'] ?? 0 }}</div>
                <div class="text-xs text-gray-500 uppercase font-semibold">Total Baris</div>
            </div>
            <div class="bg-white rounded-lg border border-emerald-200 bg-emerald-50/30 p-4 text-center dark:bg-emerald-950/20 dark:border-emerald-800">
                <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ $previewData['valid'] ?? 0 }}</div>
                <div class="text-xs text-emerald-600 dark:text-emerald-400 uppercase font-semibold">Siap Diimpor</div>
            </div>
            <div class="bg-white rounded-lg border border-amber-200 bg-amber-50/30 p-4 text-center dark:bg-amber-950/20 dark:border-amber-800">
                <div class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ $previewData['duplicate'] ?? 0 }}</div>
                <div class="text-xs text-amber-600 dark:text-amber-400 uppercase font-semibold">Sudah Terdaftar</div>
            </div>
            <div class="bg-white rounded-lg border border-red-200 bg-red-50/30 p-4 text-center dark:bg-red-950/20 dark:border-red-800">
                <div class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $previewData['invalid'] ?? 0 }}</div>
                <div class="text-xs text-red-600 dark:text-red-400 uppercase font-semibold">Tidak Valid / Error</div>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3 mb-6">
            <div class="bg-gray-50 rounded-lg border border-gray-200 p-3 text-center dark:bg-gray-800/60 dark:border-gray-700">
                <div class="text-lg font-bold text-gray-800 dark:text-gray-200">{{ $previewData['new_kk_count'] ?? 0 }}</div>
                <div class="text-[11px] text-gray-500 uppercase font-semibold">KK Baru</div>
            </div>
            <div class="bg-gray-50 rounded-lg border border-gray-200 p-3 text-center dark:bg-gray-800/60 dark:border-gray-700">
                <div class="text-lg font-bold text-gray-800 dark:text-gray-200">{{ $previewData['existing_kk_count'] ?? 0 }}</div>
                <div class="text-[11px] text-gray-500 uppercase font-semibold">KK Existing</div>
            </div>
            <div class="bg-gray-50 rounded-lg border border-gray-200 p-3 text-center dark:bg-gray-800/60 dark:border-gray-700">
                <div class="text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ $previewData['rt_valid_count'] ?? 0 }}</div>
                <div class="text-[11px] text-gray-500 uppercase font-semibold">RT Terbaca</div>
            </div>
            <div class="bg-gray-50 rounded-lg border border-gray-200 p-3 text-center dark:bg-gray-800/60 dark:border-gray-700">
                <div class="text-lg font-bold text-emerald-600 dark:text-emerald-400">{{ $previewData['rw_valid_count'] ?? 0 }}</div>
                <div class="text-[11px] text-gray-500 uppercase font-semibold">RW Terbaca</div>
            </div>
            <div class="bg-gray-50 rounded-lg border border-gray-200 p-3 text-center dark:bg-gray-800/60 dark:border-gray-700">
                @php
                    $rtRwInvalid = ($previewData['rt_invalid_count'] ?? 0) + ($previewData['rw_invalid_count'] ?? 0);
                @endphp
                <div class="text-lg font-bold {{ $rtRwInvalid > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' }}">
                    {{ $rtRwInvalid }}
                </div>
                <div class="text-[11px] text-gray-500 uppercase font-semibold">RT/RW Invalid</div>
            </div>
        </div>

        <h3 class="font-bold text-sm text-gray-900 mb-3">Tabel Preview Data</h3>
        <div class="overflow-x-auto rounded-lg border border-gray-200 mb-6">
            <table class="min-w-full divide-y divide-gray-200 text-xs">
                <thead class="bg-gray-50 font-semibold text-gray-600 uppercase tracking-wider">
                    <tr>
                        <th class="px-3 py-2.5 text-left">No</th>
                        <th class="px-3 py-2.5 text-left">NIK</th>
                        <th class="px-3 py-2.5 text-left">Nama</th>
                        <th class="px-3 py-2.5 text-left">No. KK</th>
                        <th class="px-3 py-2.5 text-left">Pendidikan</th>
                        <th class="px-3 py-2.5 text-left">Pekerjaan</th>
                        <th class="px-3 py-2.5 text-left">Hubungan</th>
                        <th class="px-3 py-2.5 text-left">Status</th>
                        <th class="px-3 py-2.5 text-left">Tgl Status</th>
                        <th class="px-3 py-2.5 text-center">Hasil</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @foreach ($previewData['preview_rows'] ?? [] as $row)
                        @php
                            $rowStatus = $row['status'] ?? 'VALID';
                            $statusLabel = match ($rowStatus) {
                                'VALID' => 'BARU',
                                'DUPLICATE' => 'EXISTING',
                                default => 'INVALID',
                            };
                            $badgeClass = match ($rowStatus) {
                                'VALID' => 'bg-emerald-100 text-emerald-800 border border-emerald-300',
                                'DUPLICATE' => 'bg-amber-100 text-amber-800 border border-amber-300',
                                default => 'bg-red-100 text-red-800 border border-red-300',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50/80">
                            <td class="px-3 py-2 text-gray-500 font-mono">{{ $row['row_number'] ?? '' }}</td>
                            <td class="px-3 py-2 font-mono font-medium text-gray-900">
                                {{ $row['nik'] ?: '-' }}
                            </td>
                            <td class="px-3 py-2 font-medium text-gray-900">
                                {{ $row['full_name'] ?: '-' }}
                                @if (! empty($row['existing_name']) && $row['existing_name'] !== $row['full_name'])
                                    <div class="text-[10px] text-amber-600 font-normal">Nama di DB: {{ $row['existing_name'] }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 font-mono text-gray-700">
                                {{ $row['kk_number'] ?: '-' }}
                            </td>
                            <td class="px-3 py-2 text-gray-700">{{ $row['education'] ?: '-' }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $row['occupation'] ?: '-' }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $row['family_relation'] ? str_replace('_', ' ', $row['family_relation']) : '-' }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $row['resident_status'] ?: 'ACTIVE' }}</td>
                            <td class="px-3 py-2 font-mono text-gray-600">{{ $row['status_date'] ?: '-' }}</td>
                            <td class="px-3 py-2 text-center whitespace-nowrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold {{ $badgeClass }}">
                                    {{ $statusLabel }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if (count($previewData['errors'] ?? []) > 0)
            <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                <p class="text-red-800 font-bold mb-2 text-sm flex items-center gap-1.5">
                    <svg class="h-4 w-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    Laporan Masalah Validasi ({{ count($previewData['errors']) }} baris):
                </p>
                <div class="max-h-56 overflow-y-auto space-y-2 text-xs">
                    @foreach ($previewData['errors'] as $rowNumber => $errors)
                        <div class="rounded bg-white p-2 border border-red-100 shadow-sm">
                            <span class="font-bold text-red-900">Baris {{ $rowNumber }}:</span>
                            <ul class="list-disc pl-5 mt-1 text-red-700 space-y-0.5">
                                @foreach ((array) $errors as $error)
                                    <li>{{ is_array($error) ? implode(', ', $error) : $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div class="p-6 bg-gray-50 rounded-lg text-center">
            <p class="text-gray-500 text-sm">Belum ada data preview. Silakan lakukan mapping kolom terlebih dahulu.</p>
        </div>
    @endif
</div>
