{{-- Phase 6.1 — Laporan Data Penduduk (PDF). Rendered via DomPDF; inline CSS only. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Data Penduduk</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #1f2937; }
        .header { text-align: center; margin-bottom: 16px; }
        .header h1 { font-size: 16px; margin: 0 0 2px; }
        .header .sub { font-size: 11px; color: #4b5563; }
        .meta { margin-bottom: 10px; font-size: 10px; }
        .meta table { width: 100%; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { border: 1px solid #9ca3af; padding: 4px 6px; text-align: left; }
        table.data th { background: #f3f4f6; font-weight: bold; }
        .footer { margin-top: 14px; font-size: 9px; color: #6b7280; text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Laporan Data Penduduk</h1>
        <div class="sub">{{ $kelurahanName ?? config('app.name') }}</div>
    </div>

    <div class="meta">
        <table>
            <tr>
                <td>Filter: {{ $filterSummary }}</td>
                <td style="text-align: right;">Dibuat: {{ $generatedAt->format('d-m-Y H:i') }}</td>
            </tr>
            <tr>
                <td>Jumlah data: {{ count($rows) }} penduduk</td>
                <td></td>
            </tr>
        </table>
    </div>

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
                    <td>{{ $row[$column] ?? '-' }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($columns) }}">Tidak ada data.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="footer">
        Dihasilkan oleh Aplikasi SIPETA — Kelurahan Tanete
    </div>
</body>
</html>
