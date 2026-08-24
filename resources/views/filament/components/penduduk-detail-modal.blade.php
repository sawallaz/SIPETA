@php
    use App\Models\Penduduk;
    /** @var Penduduk $record */
    $record = isset($getRecord) && is_callable($getRecord) ? $getRecord() : ($record ?? null);
    $ktpDoc = $record?->documents()
        ->where('document_type', 'KTP')
        ->where('is_active', true)
        ->latest('id')
        ->first();
    $aktaDoc = $record?->documents()
        ->where('document_type', 'AKTA_KELAHIRAN')
        ->where('is_active', true)
        ->latest('id')
        ->first();
    $kkPhoto = $record?->kartuKeluarga?->active_photo_full_url;
@endphp

<div class="space-y-5">
    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-gray-200 pb-4">
        <div>
            <div class="text-lg font-semibold text-gray-900">Detail Penduduk</div>
            <div class="mt-1 text-sm text-gray-500">{{ $record->full_name ?? '-' }}</div>
        </div>
        <div class="inline-flex items-center rounded-full bg-gray-50 border border-gray-200 px-3 py-1.5">
            <span class="text-xs text-gray-500 mr-1.5">NIK</span>
            <span class="text-xs font-semibold text-gray-700">{{ $record->nik ?? '-' }}</span>
        </div>
    </div>

    {{-- Row 1: Data Penduduk + Informasi Wilayah --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
        {{-- Data Penduduk --}}
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    <h3 class="text-sm font-semibold text-gray-900">Data Penduduk</h3>
                </div>
                <div class="mt-1 text-xs text-gray-500">Informasi dasar penduduk.</div>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">NIK</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $record->nik ?? '-' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Nama Lengkap</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $record->full_name ?? '-' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Jenis Kelamin</div>
                        @php
                            $genderLabel = match($record->gender?->value ?? '') {
                                'LAKI_LAKI' => 'Laki-laki',
                                'PEREMPUAN' => 'Perempuan',
                                default => '-',
                            };
                        @endphp
                        <div class="text-sm font-semibold text-gray-900">{{ $genderLabel }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Tempat Lahir</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $record->birth_place ?? '-' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Tanggal Lahir</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $record->birth_date?->format('d M Y') ?? '-' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Usia</div>
                        <div class="text-sm font-semibold text-gray-900">
                            @if(filled($record->age))
                                {{ $record->age }} tahun
                            @else
                                -
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Informasi Wilayah --}}
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <h3 class="text-sm font-semibold text-gray-900">Informasi Wilayah</h3>
                </div>
                <div class="mt-1 text-xs text-gray-500">Kartu Keluarga dan wilayah tempat tinggal.</div>
            </div>
            <div class="p-5">
                @php
                    $currentRt = $record->currentRt ?? $record->rt ?? $record->kartuKeluarga?->rt;
                    $areaUnit = $record->areaUnit ?? $currentRt?->areaUnit ?? $record->kartuKeluarga?->wilayah;
                    $rtDisplay = filled($currentRt?->number) ? 'RT ' . $currentRt->number : '-';
                    $rwDisplay = ($areaUnit && filled($areaUnit->display_label ?: $areaUnit->name))
                        ? ($areaUnit->display_label ?: $areaUnit->name)
                        : '-';
                @endphp
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Nomor KK</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $record->kartuKeluarga?->kk_number ?? '-' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">RT</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $rtDisplay }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">RW</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $rwDisplay }}</div>
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <div class="text-xs font-medium text-gray-500">Alamat</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $record->kartuKeluarga?->address ?? '-' }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 2: Data Sosial + Catatan --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
        {{-- Data Sosial --}}
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.93a2 2 0 011.664.894l5.786 5.786a2 2 0 01.219 1.346l-1.934 9.92a2 2 0 01-.995.786H9.58a2 2 0 01-1.864-.455l-1.934-9.92a2 2 0 01-.219-1.346l5.786-5.786a2 2 0 011.154-.397H18a2 2 0 012 2v5.14l-2.293-2.293z"/>
                    </svg>
                    <h3 class="text-sm font-semibold text-gray-900">Data Sosial</h3>
                </div>
                <div class="mt-1 text-xs text-gray-500">Informasi sosial dan status penduduk.</div>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-x-6 gap-y-5">
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Agama</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $record->religion?->name ?? '-' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Pendidikan</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $record->education?->name ?? '-' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Pekerjaan</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $record->occupation?->name ?? '-' }}</div>
                    </div>
                    <div class="space-y-1 xl:col-span-1">
                        <div class="text-xs font-medium text-gray-500">Status Perkawinan</div>
                        @php
                            $maritalLabel = match($record->marital_status?->value ?? '') {
                                'BELUM_KAWIN' => 'Belum Kawin',
                                'KAWIN' => 'Kawin',
                                'CERAI_HIDUP' => 'Cerai Hidup',
                                'CERAI_MATI' => 'Cerai Mati',
                                default => '-',
                            };
                        @endphp
                        <div class="text-sm font-semibold text-gray-900">{{ $maritalLabel }}</div>
                    </div>
                    <div class="space-y-1 sm:col-span-1 xl:col-span-1">
                        <div class="text-xs font-medium text-gray-500">Status Penduduk</div>
                        <div class="mt-1">
                            @php $statusLabel = match($record->resident_status?->value ?? '') {
                                'ACTIVE' => 'Aktif',
                                'PINDAH' => 'Pindah',
                                'MENINGGAL' => 'Meninggal',
                                default => '-',
                            }; @endphp
                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium bg-gray-100 text-gray-800">
                                {{ $statusLabel }}
                            </span>
                        </div>
                    </div>
                    <div class="space-y-1 sm:col-span-1 xl:col-span-1">
                        <div class="text-xs font-medium text-gray-500">{{ $record->status_date_label }}</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $record->formatted_status_date ?? '-' }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Riwayat Status & KK --}}
        @php
            $statusHistories = $record->statusHistories()->latest('recorded_at')->latest('id')->get();
            $kkHistories = $record->kkAnggotas()->with('kartuKeluarga')->orderByDesc('effective_date')->get();
        @endphp
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <h3 class="text-sm font-semibold text-gray-900">Riwayat Status &amp; KK</h3>
                </div>
                <div class="mt-1 text-xs text-gray-500">Kronologi perubahan status kependudukan dan Kartu Keluarga.</div>
            </div>
            <div class="p-5 space-y-4">
                <div>
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Riwayat Status</div>
                    @if($statusHistories->isNotEmpty())
                        <ul class="divide-y divide-gray-100">
                            @foreach($statusHistories as $history)
                                @php
                                    $hDate = $history->recorded_at ? \Carbon\Carbon::parse($history->recorded_at)->locale('id')->translatedFormat('d F Y') : '-';
                                    $hStatus = match($history->status?->value ?? (string)$history->status) {
                                        'PINDAH' => 'Pindah',
                                        'MENINGGAL' => 'Meninggal',
                                        default => 'Aktif',
                                    };
                                @endphp
                                <li class="py-1.5 flex items-center justify-between text-sm">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-gray-900">{{ $hDate }}</span>
                                        <span class="text-gray-400">—</span>
                                        <span class="text-gray-700">{{ $hStatus }}</span>
                                        @if($history->notes)
                                            <span class="text-xs text-gray-500">({{ $history->notes }})</span>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="text-sm text-gray-600">
                            {{ $record->formatted_status_date ?? '-' }} — {{ $statusLabel }}
                        </div>
                    @endif
                </div>

                @if($kkHistories->count() > 1)
                <div class="pt-3 border-t border-gray-100">
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Riwayat Kartu Keluarga</div>
                    <ul class="divide-y divide-gray-100">
                        @foreach($kkHistories as $kkH)
                            @php
                                $effDate = $kkH->effective_date ? \Carbon\Carbon::parse($kkH->effective_date)->locale('id')->translatedFormat('d M Y') : '-';
                                $endDate = $kkH->end_date ? \Carbon\Carbon::parse($kkH->end_date)->locale('id')->translatedFormat('d M Y') : 'Sekarang';
                                $rel = match($kkH->family_relation?->value ?? (string)$kkH->family_relation) {
                                    'KEPALA_KELUARGA' => 'Kepala Keluarga',
                                    'ISTRI' => 'Istri',
                                    'ANAK' => 'Anak',
                                    default => 'Anggota',
                                };
                                $st = match($kkH->status?->value ?? (string)$kkH->status) {
                                    'AKTIF' => 'KK Saat Ini (Aktif)',
                                    'KELUAR' => 'Pindah / KK Sebelumnya',
                                    default => '-',
                                };
                            @endphp
                            <li class="py-1.5 flex items-center justify-between text-xs">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-900">KK {{ $kkH->kartuKeluarga?->kk_number ?? '-' }}</span>
                                    <span class="text-gray-500">({{ $rel }})</span>
                                    <span class="text-gray-400">—</span>
                                    <span class="{{ $kkH->status?->value === 'AKTIF' ? 'text-green-600 font-medium' : 'text-gray-500' }}">{{ $st }}</span>
                                </div>
                                <div class="text-gray-400 text-right">{{ $effDate }} s/d {{ $endDate }}</div>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Catatan --}}
    @if(filled($record->notes))
    <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                </svg>
                <h3 class="text-sm font-semibold text-gray-900">Catatan</h3>
            </div>
        </div>
        <div class="p-5">
            <div class="text-sm text-gray-700 whitespace-pre-wrap">{{ $record->notes }}</div>
        </div>
    </div>
    @endif

    {{-- Row 3: Dokumen --}}
    <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 10v4a2 2 0 002 2h6a2 2 0 002-2v-4m-6 4h6m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <h3 class="text-sm font-semibold text-gray-900">Dokumen</h3>
            </div>
            <div class="mt-1 text-xs text-gray-500">Dokumen penduduk: KTP, Akta Kelahiran, dan Foto KK.</div>
        </div>
        <div class="p-5">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                {{-- KTP --}}
                <div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50/50 overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-200 bg-white">
                            <div class="text-sm font-semibold text-gray-900">KTP</div>
                            <div class="text-xs text-gray-500 mt-0.5">Kartu Tanda Penduduk</div>
                        </div>
                        <div class="p-3">
                            @if($ktpDoc)
                                <a href="{{ route('penduduk-documents.preview', $ktpDoc) }}" target="_blank" class="block h-40 w-full rounded-lg border border-gray-200 bg-white overflow-hidden hover:opacity-90 transition">
                                    <img src="{{ route('penduduk-documents.preview', $ktpDoc) }}" alt="KTP" class="h-full w-full object-contain">
                                </a>
                            @else
                                <div class="h-40 rounded-lg border border-dashed border-gray-300 bg-white flex items-center justify-center text-center">
                                    <div>
                                        <div class="text-sm font-medium text-gray-500">Belum ada dokumen</div>
                                        <div class="mt-1 text-xs text-gray-400">Dokumen belum tersedia.</div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
                {{-- Akta Kelahiran --}}
                <div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50/50 overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-200 bg-white">
                            <div class="text-sm font-semibold text-gray-900">Akta Kelahiran</div>
                            <div class="text-xs text-gray-500 mt-0.5">Akta Kelahiran</div>
                        </div>
                        <div class="p-3">
                            @if($aktaDoc)
                                <a href="{{ route('penduduk-documents.preview', $aktaDoc) }}" target="_blank" class="block h-40 w-full rounded-lg border border-gray-200 bg-white overflow-hidden hover:opacity-90 transition">
                                    <img src="{{ route('penduduk-documents.preview', $aktaDoc) }}" alt="Akta Kelahiran" class="h-full w-full object-contain">
                                </a>
                            @else
                                <div class="h-40 rounded-lg border border-dashed border-gray-300 bg-white flex items-center justify-center text-center">
                                    <div>
                                        <div class="text-sm font-medium text-gray-500">Belum ada dokumen</div>
                                        <div class="mt-1 text-xs text-gray-400">Dokumen belum tersedia.</div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
                {{-- Foto KK --}}
                <div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50/50 overflow-hidden">
                        <div class="px-4 py-3 border-b border-gray-200 bg-white">
                            <div class="text-sm font-semibold text-gray-900">Foto KK</div>
                            <div class="text-xs text-gray-500 mt-0.5">Foto Kartu Keluarga</div>
                        </div>
                        <div class="p-3">
                            @if($kkPhoto)
                                <a href="{{ $kkPhoto }}" target="_blank" class="block h-40 w-full rounded-lg border border-gray-200 bg-white overflow-hidden hover:opacity-90 transition">
                                    <img src="{{ $kkPhoto }}" alt="Foto Kartu Keluarga" class="h-full w-full object-contain">
                                </a>
                            @else
                                <div class="h-40 rounded-lg border border-dashed border-gray-300 bg-white flex items-center justify-center text-center">
                                    <div>
                                        <div class="text-sm font-medium text-gray-500">Belum ada foto</div>
                                        <div class="mt-1 text-xs text-gray-400">Foto KK belum tersedia.</div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
