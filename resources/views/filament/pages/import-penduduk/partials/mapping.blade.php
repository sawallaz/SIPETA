<div class="bg-white rounded-lg border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">3. Mapping Kolom</h2>
    <p class="text-gray-600 mb-4">Periksa hasil pencocokan kolom file Excel dengan field data kependudukan SIPETA.</p>

    @php
        $mapping = $page->mapping;
        $ambiguous = $page->ambiguous;
        $missingRequired = $page->missingRequired;
        $unrecognized = $page->unrecognized;

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
            'family_relation' => 'Hubungan Keluarga',
            'rt' => 'RT',
            'rw' => 'RW',
            'lingkungan' => 'Lingkungan',
            'address' => 'Alamat',
            'resident_status' => 'Status Penduduk',
            'active_at' => 'Tanggal Status / Aktif',
            'moved_at' => 'Tanggal Pindah',
            'deceased_at' => 'Tanggal Meninggal',
            'notes' => 'Catatan',
        ];
    @endphp

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Excel Header (Kolom File)</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SIPETA Field</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @foreach ($mapping['mapping'] ?? [] as $field => $sourceHeader)
                    @php
                        $isAmbiguous = isset($ambiguous[$field]);
                        $rowClass = $isAmbiguous ? 'bg-amber-50' : '';
                        $label = $fieldLabels[$field] ?? ucfirst(str_replace('_', ' ', $field));
                    @endphp
                    <tr class="{{ $rowClass }}">
                        <td class="px-4 py-3 text-sm font-mono font-medium text-gray-900">
                            @if ($isAmbiguous)
                                <select wire:change="updateMapping('{{ $field }}', $event.target.value)" 
                                    class="block w-full text-sm border-gray-300 rounded-md shadow-sm focus:border-primary focus:ring-primary"
                                >
                                    <option value="">-- Pilih Kolom File --</option>
                                    @foreach ($ambiguous[$field] as $option)
                                        <option value="{{ $option }}" {{ $sourceHeader === $option ? 'selected' : '' }}>{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span class="text-gray-900">{{ $sourceHeader }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm font-semibold text-gray-800">
                            {{ $label }}
                        </td>
                        <td class="px-4 py-3">
                            @if ($isAmbiguous)
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">Perlu Pilih</span>
                            @else
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Terpetakan</span>
                            @endif
                        </td>
                    </tr>
                @endforeach

                @foreach ($unrecognized as $unmappedHeader)
                    <tr class="bg-gray-50/50">
                        <td class="px-4 py-3 text-sm font-mono text-gray-500">{{ $unmappedHeader }}</td>
                        <td class="px-4 py-3 text-sm text-gray-400 italic">(Tidak dipetakan)</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-200 text-gray-700">Tidak Dikenali</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (count($missingRequired) > 0)
        <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p class="text-red-700 font-bold mb-1">Kolom wajib tidak ditemukan:</p>
            <ul class="text-sm text-red-600 list-disc pl-5 space-y-0.5">
                @foreach ($missingRequired as $field)
                    <li><strong class="font-semibold">{{ $fieldLabels[$field] ?? $field }}</strong></li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <p class="text-blue-700 text-xs leading-relaxed">
            <span class="font-semibold">Catatan:</span> Sistem mencocokkan nama header secara cerdas (alias resmi). Kolom yang tidak dikenali akan diabaikan tanpa merusak data lain.
        </p>
    </div>
</div>
