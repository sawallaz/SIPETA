<div class="bg-white rounded-lg border border-gray-200 p-6">
    @if ($page->completedImportResult)
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Import Selesai</h2>
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="rounded-lg border border-green-200 p-4 text-center">
                <div class="text-2xl font-bold text-green-600">{{ $page->completedImportResult['total_imported'] ?? 0 }}</div>
                <div class="text-xs text-gray-500 uppercase">Berhasil Diimpor</div>
            </div>
            <div class="rounded-lg border border-amber-200 p-4 text-center">
                <div class="text-2xl font-bold text-amber-600">{{ $page->completedImportResult['duplicate_count'] ?? 0 }}</div>
                <div class="text-xs text-gray-500 uppercase">Duplikat</div>
            </div>
            <div class="rounded-lg border border-red-200 p-4 text-center">
                <div class="text-2xl font-bold text-red-600">{{ $page->completedImportResult['invalid_count'] ?? 0 }}</div>
                <div class="text-xs text-gray-500 uppercase">Ditolak</div>
            </div>
        </div>
        <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
            <p class="text-green-700 text-sm">{{ $page->completedImportResult['message'] ?? 'Import selesai.' }}</p>
        </div>
    @else
        <h2 class="text-lg font-semibold text-gray-900 mb-4">5. Konfirmasi Import</h2>
        <p class="text-gray-600 mb-4">Data siap diimpor ke database.</p>

        @php($previewData = $page->previewData)
        <div class="grid grid-cols-4 gap-4 mb-6">
            <div class="rounded-lg border border-gray-200 p-4 text-center"><div class="text-2xl font-bold text-gray-700">{{ $previewData['total'] ?? 0 }}</div><div class="text-xs text-gray-500 uppercase">Total</div></div>
            <div class="rounded-lg border border-green-200 p-4 text-center"><div class="text-2xl font-bold text-green-600">{{ $previewData['valid'] ?? 0 }}</div><div class="text-xs text-gray-500 uppercase">Akan Diimpor</div></div>
            <div class="rounded-lg border border-amber-200 p-4 text-center"><div class="text-2xl font-bold text-amber-600">{{ $previewData['duplicate'] ?? 0 }}</div><div class="text-xs text-gray-500 uppercase">Duplikat</div></div>
            <div class="rounded-lg border border-red-200 p-4 text-center"><div class="text-2xl font-bold text-red-600">{{ $previewData['invalid'] ?? 0 }}</div><div class="text-xs text-gray-500 uppercase">Ditolak</div></div>
        </div>
        @if (count($previewData['errors'] ?? []) > 0)
            <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg mb-4"><p class="text-amber-700 text-sm"><span class="font-medium">Peringatan:</span> {{ count($previewData['errors']) }} baris tidak valid tidak akan diimpor.</p></div>
        @endif
        <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg"><p class="text-blue-700 text-sm"><span class="font-medium">Informasi:</span> Setelah import selesai, file temporary akan dihapus.</p></div>
    @endif
</div>
