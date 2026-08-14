@php
    /** @var \App\Models\KartuKeluarga $kk */
    $head = $kk->kepalaKeluarga();
    $members = $kk->penduduks;

    $statusLabels = [
        'ACTIVE' => 'Aktif',
        'PINDAH' => 'Pindah',
        'MENINGGAL' => 'Meninggal',
    ];
@endphp

<div class="space-y-5">
    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-gray-200 pb-4">
        <div>
            <div class="text-lg font-semibold text-gray-900">Kartu Keluarga</div>
            <div class="mt-1 text-sm text-gray-500">Kepala Keluarga: {{ $head?->full_name ?? '-' }}</div>
        </div>
        <div class="inline-flex items-center rounded-full bg-gray-50 border border-gray-200 px-3 py-1.5">
            <span class="text-xs text-gray-500 mr-1.5">Nomor KK</span>
            <span class="text-xs font-semibold text-gray-700">{{ $kk->kk_number ?? '-' }}</span>
        </div>
    </div>

    {{-- Row 1: Informasi KK + Foto KK --}}
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
        {{-- Informasi Kartu Keluarga --}}
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <h3 class="text-sm font-semibold text-gray-900">Informasi Kartu Keluarga</h3>
                </div>
                <div class="mt-1 text-xs text-gray-500">Informasi utama administrasi Kartu Keluarga.</div>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Nomor KK</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $kk->kk_number ?? '-' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Kepala Keluarga</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $head?->full_name ?? '-' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Wilayah</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $kk->rt_rw_label ?? '-' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Jumlah Anggota</div>
                        <div class="text-sm font-semibold text-gray-900">{{ number_format($kk->jumlah_anggota, 0, ',', '.') }} orang</div>
                    </div>
                    <div class="space-y-1 sm:col-span-2">
                        <div class="text-xs font-medium text-gray-500">Alamat</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $kk->address ?? '-' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-medium text-gray-500">Kode Pos</div>
                        <div class="text-sm font-semibold text-gray-900">{{ $kk->postal_code ?? '-' }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Foto Kartu Keluarga --}}
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-200">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <h3 class="text-sm font-semibold text-gray-900">Foto Kartu Keluarga</h3>
                </div>
                <div class="mt-1 text-xs text-gray-500">Arsip dokumen foto Kartu Keluarga.</div>
            </div>
            <div class="p-5">
                @if ($kk->active_photo_full_url)
                    <div class="space-y-3">
                        <a href="{{ $kk->active_photo_full_url }}" target="_blank" class="block h-48 w-full rounded-lg border border-gray-200 bg-gray-50 overflow-hidden hover:opacity-90 transition">
                            <img src="{{ $kk->active_photo_full_url }}" alt="Foto Kartu Keluarga" class="h-full w-full object-contain">
                        </a>
                        <div class="text-center">
                            <a href="{{ $kk->active_photo_full_url }}" target="_blank" class="text-xs font-medium text-primary-600 hover:text-primary-700">
                                Buka foto ukuran penuh
                            </a>
                        </div>
                    </div>
                @else
                    <div class="h-48 rounded-lg border border-dashed border-gray-300 bg-gray-50/50 flex flex-col items-center justify-center text-center p-4">
                        <svg class="h-8 w-8 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <span class="text-sm font-medium text-gray-600">Belum ada foto KK</span>
                        <span class="text-xs text-gray-400 mt-1">Upload foto melalui menu Ubah.</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Row 2: Anggota Keluarga --}}
    <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                    <h3 class="text-sm font-semibold text-gray-900">Anggota Keluarga</h3>
                </div>
                <div class="mt-1 text-xs text-gray-500">Daftar penduduk yang terdaftar dalam Kartu Keluarga.</div>
            </div>
            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
                {{ $members->count() }} orang
            </span>
        </div>

        <div class="p-5">
            @if ($members->isEmpty())
                <div class="text-center py-6 text-sm text-gray-500">
                    Belum ada anggota keluarga.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50 text-xs font-semibold text-gray-700 uppercase tracking-wider border-b border-gray-200">
                            <tr>
                                <th class="px-4 py-3 text-center">No</th>
                                <th class="px-4 py-3">NIK</th>
                                <th class="px-4 py-3">Nama Lengkap</th>
                                <th class="px-4 py-3 text-center">JK</th>
                                <th class="px-4 py-3">Tanggal Lahir</th>
                                <th class="px-4 py-3">Usia</th>
                                <th class="px-4 py-3">Hubungan</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            @foreach ($members as $index => $penduduk)
                                <tr class="hover:bg-gray-50/50">
                                    <td class="px-4 py-3 text-center text-xs font-mono text-gray-500">
                                        {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs font-medium text-gray-900">
                                        {{ $penduduk->nik }}
                                    </td>
                                    <td class="px-4 py-3 font-medium text-gray-900">
                                        {{ $penduduk->full_name }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        {{ $penduduk->gender === \App\Enums\Gender::PEREMPUAN ? 'P' : 'L' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        {{ $penduduk->birth_date?->format('d M Y') ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        {{ $penduduk->birth_date ? $penduduk->age . ' th' : '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        @switch($penduduk->family_relation?->value)
                                            @case('KEPALA_KELUARGA')
                                                <span class="font-semibold text-gray-900">Kepala Keluarga</span>
                                                @break
                                            @case('ISTRI')
                                                Istri
                                                @break
                                            @case('ANAK')
                                                Anak
                                                @break
                                            @case('MENANTU')
                                                Menantu
                                                @break
                                            @case('CUCU')
                                                Cucu
                                                @break
                                            @case('ORANG_TUA')
                                                Orang Tua
                                                @break
                                            @case('MERTUA')
                                                Mertua
                                                @break
                                            @case('FAMILI_LAIN')
                                                Famili Lain
                                                @break
                                            @default
                                                Lainnya
                                        @endswitch
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        @php $stVal = $penduduk->resident_status?->value ?? 'ACTIVE'; @endphp
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium 
                                            {{ match($stVal) {
                                                'ACTIVE' => 'bg-green-100 text-green-800',
                                                'PINDAH' => 'bg-yellow-100 text-yellow-800',
                                                'MENINGGAL' => 'bg-red-100 text-red-800',
                                                default => 'bg-gray-100 text-gray-800',
                                            } }}">
                                            {{ $statusLabels[$stVal] ?? 'Aktif' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
