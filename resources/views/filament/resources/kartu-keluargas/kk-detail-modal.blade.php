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

<div class="sipeta-kk-modal">

    {{-- HEADER --}}
    <div class="sipeta-kk-modal-header">
        <div>
            <div class="sipeta-kk-modal-title">
                Kartu Keluarga
            </div>

            <div class="sipeta-kk-modal-number">
                {{ $kk->kk_number }}
            </div>
        </div>
    </div>

    {{-- INFORMASI UTAMA --}}
    <div class="sipeta-kk-top-grid">

        {{-- FOTO --}}
        <section class="sipeta-kk-card sipeta-kk-photo-card">

            <div class="sipeta-kk-card-header">
                <div>
                    <h3>Foto Kartu Keluarga</h3>
                    <p>Arsip dokumen Kartu Keluarga.</p>
                </div>
            </div>

            <div class="sipeta-kk-photo-content">
                @if ($kk->active_photo_full_url)

                    <a
                        href="{{ $kk->active_photo_full_url }}"
                        target="_blank"
                        class="sipeta-kk-photo-link"
                    >
                        <img
                            src="{{ $kk->active_photo_full_url }}"
                            alt="Foto Kartu Keluarga"
                            class="sipeta-kk-photo"
                        >
                    </a>

                    <a
                        href="{{ $kk->active_photo_full_url }}"
                        target="_blank"
                        class="sipeta-kk-photo-open"
                    >
                        Buka foto ukuran penuh
                    </a>

                @else

                    <div class="sipeta-kk-no-photo">
                        <div class="sipeta-kk-no-photo-icon">
                            <x-heroicon-o-photo />
                        </div>

                        <strong>Belum ada foto KK</strong>

                        <span>
                            Upload foto melalui menu Ubah.
                        </span>
                    </div>

                @endif
            </div>

        </section>


        {{-- INFORMASI KK --}}
        <section class="sipeta-kk-card">

            <div class="sipeta-kk-card-header">
                <div>
                    <h3>Informasi Kartu Keluarga</h3>
                    <p>Informasi utama Kartu Keluarga.</p>
                </div>
            </div>

            <div class="sipeta-kk-info-grid">

                <div class="sipeta-kk-info-item">
                    <span>Nomor KK</span>
                    <strong>{{ $kk->kk_number }}</strong>
                </div>

                <div class="sipeta-kk-info-item">
                    <span>Kepala Keluarga</span>
                    <strong>{{ $head?->full_name ?? '-' }}</strong>
                </div>

                {{--
                    Satu baris wilayah saja. `rt_rw_label` sudah menggabungkan
                    AreaUnit + RT menjadi "Lingkungan I / RT 02", sehingga baris
                    "Lingkungan" terpisah hanya mengulang informasi yang sama.
                --}}
                <div class="sipeta-kk-info-item">
                    <span>Wilayah</span>
                    <strong>{{ $kk->rt_rw_label ?? '-' }}</strong>
                </div>

                <div class="sipeta-kk-info-item">
                    <span>Jumlah Anggota</span>
                    <strong>
                        {{ number_format($kk->jumlah_anggota, 0, ',', '.') }}
                        orang
                    </strong>
                </div>

                <div class="sipeta-kk-info-item">
                    <span>Kode Pos</span>
                    <strong>{{ $kk->postal_code ?? '-' }}</strong>
                </div>

            </div>

            <div class="sipeta-kk-address">
                <span>Alamat</span>
                <strong>{{ $kk->address ?? '-' }}</strong>
            </div>

        </section>

    </div>


    {{-- ANGGOTA KELUARGA --}}
    <section class="sipeta-kk-card sipeta-kk-members-card">

        <div class="sipeta-kk-card-header sipeta-kk-members-header">

            <div>
                <h3>Anggota Keluarga</h3>
                <p>
                    Daftar penduduk yang terdaftar dalam Kartu Keluarga.
                </p>
            </div>

            <span class="sipeta-kk-count">
                {{ $members->count() }} orang
            </span>

        </div>


        @if ($members->isEmpty())

            <div class="sipeta-kk-empty">
                Belum ada anggota keluarga.
            </div>

        @else

            <div class="sipeta-kk-table-wrapper">

                <table class="sipeta-kk-table">

                    <thead>
                        <tr>
                            <th>No</th>
                            <th>NIK</th>
                            <th>Nama Lengkap</th>
                            <th>JK</th>
                            <th>Tanggal Lahir</th>
                            <th>Usia</th>
                            <th>Hubungan</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        @foreach ($members as $index => $penduduk)

                            <tr>

                                <td class="sipeta-kk-no">
                                    {{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}
                                </td>

                                <td class="sipeta-kk-nik">
                                    {{ $penduduk->nik }}
                                </td>

                                <td class="sipeta-kk-name">
                                    {{ $penduduk->full_name }}
                                </td>

                                <td>
                                    {{ $penduduk->gender === \App\Enums\Gender::PEREMPUAN ? 'P' : 'L' }}
                                </td>

                                <td>
                                    {{ $penduduk->birth_date?->format('d M Y') ?? '-' }}
                                </td>

                                <td>
                                    {{ $penduduk->birth_date ? $penduduk->age . ' th' : '-' }}
                                </td>

                                <td>
                                    @switch($penduduk->family_relation?->value)

                                        @case('KEPALA_KELUARGA')
                                            Kepala Keluarga
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

                                <td>
                                    <span class="sipeta-kk-status">
                                        {{ $statusLabels[$penduduk->resident_status?->value] ?? 'Aktif' }}
                                    </span>
                                </td>

                            </tr>

                        @endforeach

                    </tbody>

                </table>

            </div>

        @endif

    </section>

</div>
