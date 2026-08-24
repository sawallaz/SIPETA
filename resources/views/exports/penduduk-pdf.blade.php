<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">

    <title>Laporan Data Penduduk</title>

    <style>
        * {
            font-family: DejaVu Sans, sans-serif;
        }

        @page {
            margin: 20px 18px 18px 18px;
        }

        body {
            font-size: 7px;
            color: #111827;
        }

        .kop {
            width: 100%;
            border-bottom: 2px solid #111827;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }

        .kop-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logo-cell {
            width: 70px;
            text-align: center;
            vertical-align: middle;
        }

        .logo {
            max-width: 55px;
            max-height: 55px;
        }

        .identity {
            text-align: center;
            vertical-align: middle;
        }

        .identity .kelurahan {
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .identity .address {
            font-size: 8px;
            margin-top: 2px;
        }

        .title {
            text-align: center;
            margin-top: 8px;
            margin-bottom: 6px;
        }

        .title h1 {
            font-size: 12px;
            margin: 0;
            text-transform: uppercase;
        }

        .title p {
            margin: 3px 0 0;
            font-size: 8px;
        }

        .meta {
            margin-bottom: 6px;
            font-size: 7px;
        }

        .meta table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta td {
            padding: 1px 0;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.data thead {
            display: table-header-group;
        }

        table.data tr {
            page-break-inside: avoid;
        }

        table.data th,
        table.data td {
            border: 0.5px solid #9ca3af;
            padding: 2px 2px;
            vertical-align: top;
            line-height: 1.15;
            overflow-wrap: break-word;
            word-wrap: break-word;
        }

        table.data th {
            background: #f3f4f6;
            font-weight: bold;
            font-size: 5.5px;
            text-align: center;
        }

        table.data td {
            font-size: 5.5px;
        }

        .footer {
            position: fixed;
            bottom: -20px;
            left: 0;
            right: 0;
            text-align: right;
            font-size: 8px;
            color: #6b7280;
        }
    </style>
</head>

<body>

    {{-- KOP SURAT --}}
    <div class="kop">
        <table class="kop-table">
            <tr>
                <td class="logo-cell">
                    @if (!empty($logoData))
                        <img
                            src="{{ $logoData }}"
                            class="logo"
                        >
                    @endif
                </td>

                <td class="identity">
                    <div class="kelurahan">
                        {{ $kelurahanName ?? 'Kelurahan' }}
                    </div>

                    <div class="address">
                        Kecamatan {{ $kecamatanName ?? '-' }}
                    </div>

                    <div class="address">
                        {{ $kabupatenName ?? '-' }},
                        {{ $provinceName ?? '-' }}
                    </div>
                </td>

                <td style="width: 80px;"></td>
            </tr>
        </table>
    </div>

    {{-- JUDUL --}}
    <div class="title">
        <h1>Laporan Data Penduduk</h1>

        <p>
            Data Penduduk Kelurahan
            {{ $kelurahanName ?? '-' }}
        </p>
    </div>

    {{-- INFORMASI LAPORAN --}}
    <div class="meta">
        <table>
            <tr>
                <td>
                    <strong>Filter:</strong>
                    {{ $filterSummary ?: 'Semua Data' }}
                </td>

                <td style="text-align: right;">
                    <strong>Dibuat:</strong>
                    {{ $generatedAt->format('d-m-Y H:i') }}
                </td>
            </tr>

            <tr>
                <td>
                    <strong>Jumlah Data:</strong>
                    {{ count($rows) }} penduduk
                </td>

                <td></td>
            </tr>
        </table>
    </div>

    {{-- DATA PENDUDUK --}}
    <table class="data">
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($columns as $column)
                        <td>
                            {{ $row[$column] ?? '-' }}
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) }}">
                        Tidak ada data penduduk.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Dihasilkan oleh SIPETA —
        {{ $kelurahanName ?? 'Kelurahan' }}
    </div>

</body>
</html>
