<div class="bg-white rounded-lg border border-gray-200 p-6 shadow-sm dark:bg-gray-800 dark:border-gray-700">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">3. Mapping Kolom Excel ke SIPETA</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-0.5">Periksa hasil pencocokan kolom. Minimal 3 field wajib (<span class="font-semibold text-emerald-600 dark:text-emerald-400">NIK, Nama Lengkap, Nomor KK</span>), kolom lainnya bersifat opsional.</p>
        </div>
        <button
            type="button"
            wire:click="confirmMapping"
            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-700 transition"
        >
            <span>Lanjutkan ke Preview &rarr;</span>
        </button>
    </div>

    @php
        $mapping = isset($page) ? $page->mapping : ($mapping ?? []);
        $ambiguous = isset($page) ? $page->ambiguous : ($ambiguous ?? []);
        $missingRequired = isset($page) ? $page->missingRequired : ($missingRequired ?? []);
        $allAvailableHeaders = isset($page) ? $page->headers : ($headers ?? []);

        $fieldLabels = [
            'nik' => 'NIK',
            'full_name' => 'Nama Lengkap',
            'kk_number' => 'Nomor KK',
            'gender' => 'Jenis Kelamin',
            'birth_place' => 'Tempat Lahir',
            'birth_date' => 'Tanggal Lahir',
            'religion' => 'Agama',
            'education' => 'Pendidikan',
            'occupation' => 'Pekerjaan',
            'marital_status' => 'Status Perkawinan',
            'family_relation' => 'Hubungan Keluarga / SHDK',
            'rt' => 'RT',
            'rw' => 'RW',
            'lingkungan' => 'Lingkungan',
            'address' => 'Alamat',
            'citizenship' => 'Kewarganegaraan',
            'father_name' => 'Nama Ayah',
            'mother_name' => 'Nama Ibu',
            'postal_code' => 'Kode Pos',
            'notes' => 'Catatan',
        ];
    @endphp

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700 mb-6">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-900/50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider dark:text-gray-400 w-1/3">Target Field SIPETA</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider dark:text-gray-400 w-1/2">Kolom File Excel</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider dark:text-gray-400">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                @foreach ($fieldLabels as $field => $label)
                    @php
                        $selectedHeader = $mapping['mapping'][$field] ?? '';
                        $isAmbiguous = isset($ambiguous[$field]);
                        $isRequired = in_array($field, ['nik', 'full_name', 'kk_number'], true);
                        $isMapped = filled($selectedHeader);
                    @endphp
                    <tr class="{{ $isAmbiguous ? 'bg-amber-50/70 dark:bg-amber-950/30' : ($isRequired && !$isMapped ? 'bg-red-50/40 dark:bg-red-950/20' : '') }}">
                        <td class="px-4 py-3 text-sm">
                            <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $label }}</span>
                            @if ($isRequired)
                                <span class="ml-1 text-xs text-red-500 font-bold">* (Wajib)</span>
                            @else
                                <span class="ml-1 text-xs text-gray-400 font-normal">(Opsional)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <select
                                wire:change="updateMapping('{{ $field }}', $event.target.value)"
                                class="block w-full text-sm border-gray-300 rounded-md shadow-sm bg-white dark:bg-gray-900 dark:border-gray-600 focus:border-primary-500 focus:ring-primary-500 {{ $isAmbiguous ? 'border-amber-400 bg-amber-50/60 dark:border-amber-600' : '' }}"
                            >
                                <option value="">-- Tidak Dipetakan / Kosong --</option>
                                @foreach ($allAvailableHeaders as $h)
                                    <option value="{{ $h }}" {{ $selectedHeader === $h ? 'selected' : '' }}>
                                        {{ $h }} {{ ($selectedHeader === $h) ? '(Terhubung)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-4 py-3 text-sm whitespace-nowrap">
                            @if ($isAmbiguous)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                    Pilih Kolom
                                </span>
                            @elseif ($isMapped)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200">
                                    ✓ Terpetakan
                                </span>
                            @elseif ($isRequired)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                    ! Belum Terhubung
                                </span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                    Kosong
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (count($missingRequired) > 0)
        <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg dark:bg-red-950/40 dark:border-red-800">
            <p class="text-red-800 font-bold mb-1 dark:text-red-300">Kolom wajib belum terpetakan:</p>
            <ul class="text-sm text-red-700 list-disc pl-5 space-y-0.5 dark:text-red-400">
                @foreach ($missingRequired as $field)
                    <li><strong class="font-semibold">{{ $fieldLabels[$field] ?? $field }}</strong></li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between pt-2">
        <button
            type="button"
            wire:click="goToSheet"
            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 transition dark:bg-gray-800 dark:border-gray-600 dark:text-gray-300"
        >
            <span>&larr; Kembali ke Sheet</span>
        </button>

        <button
            type="button"
            wire:click="confirmMapping"
            class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 transition"
        >
            <span>Lanjutkan ke Preview &rarr;</span>
        </button>
    </div>
</div>
