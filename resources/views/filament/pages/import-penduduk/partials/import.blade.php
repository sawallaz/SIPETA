<div class="bg-white rounded-lg border border-gray-200 p-6">
    {{-- Loading state saat proses import sedang berlangsung --}}
    <div wire:loading wire:target="importData" class="mb-6 p-4 bg-primary/10 border border-primary/20 rounded-lg">
        <div class="flex items-center gap-3 text-primary font-medium text-sm">
            <x-filament::loading-indicator class="h-5 w-5 animate-spin" />
            <span>Sedang mengimpor data penduduk ke dalam database... Mohon tunggu.</span>
        </div>
    </div>

    @if ($page->completedImportResult)
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Import Selesai</h2>
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="rounded-lg border border-green-200 p-4 text-center">
                <div class="text-2xl font-bold text-green-600">{{ $page->completedImportResult['total_imported'] ?? 0 }}</div>
                <div class="text-xs text-gray-500 uppercase font-semibold">Berhasil Diimpor</div>
            </div>
            <div class="rounded-lg border border-amber-200 p-4 text-center">
                <div class="text-2xl font-bold text-amber-600">{{ $page->completedImportResult['duplicate_count'] ?? 0 }}</div>
                <div class="text-xs text-gray-500 uppercase font-semibold">Duplikat</div>
            </div>
            <div class="rounded-lg border border-red-200 p-4 text-center">
                <div class="text-2xl font-bold text-red-600">{{ $page->completedImportResult['invalid_count'] ?? 0 }}</div>
                <div class="text-xs text-gray-500 uppercase font-semibold">Ditolak</div>
            </div>
        </div>
        <div class="p-4 bg-green-50 border border-green-200 rounded-lg">
            <p class="text-green-700 text-sm font-medium">{{ $page->completedImportResult['message'] ?? 'Import selesai.' }}</p>
        </div>
    @else
        <div wire:loading.remove wire:target="importData">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">5. Konfirmasi Import</h2>
            <p class="text-gray-600 mb-4">Data siap diimpor ke database SIPETA.</p>

            @php($previewData = $page->previewData)
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-4">
                <div class="rounded-lg border border-gray-200 p-3 text-center"><div class="text-xl font-bold text-gray-700">{{ $previewData['total'] ?? 0 }}</div><div class="text-xs text-gray-500 uppercase font-semibold">Total Baris</div></div>
                <div class="rounded-lg border border-green-200 bg-green-50/30 p-3 text-center"><div class="text-xl font-bold text-green-600">{{ $previewData['valid'] ?? 0 }}</div><div class="text-xs text-green-600 uppercase font-semibold">Penduduk Valid</div></div>
                <div class="rounded-lg border border-blue-200 bg-blue-50/30 p-3 text-center"><div class="text-xl font-bold text-blue-600">{{ $previewData['new_kk'] ?? 0 }}</div><div class="text-xs text-blue-600 uppercase font-semibold">KK Baru</div></div>
                <div class="rounded-lg border border-cyan-200 bg-cyan-50/30 p-3 text-center"><div class="text-xl font-bold text-cyan-600">{{ $previewData['existing_kk'] ?? 0 }}</div><div class="text-xs text-cyan-600 uppercase font-semibold">KK Existing</div></div>
                <div class="rounded-lg border border-amber-200 bg-amber-50/30 p-3 text-center"><div class="text-xl font-bold text-amber-600">{{ $previewData['duplicate'] ?? 0 }}</div><div class="text-xs text-amber-600 uppercase font-semibold">NIK Duplikat</div></div>
                <div class="rounded-lg border border-red-200 bg-red-50/30 p-3 text-center"><div class="text-xl font-bold text-red-600">{{ $previewData['invalid'] ?? 0 }}</div><div class="text-xs text-red-600 uppercase font-semibold">Ditolak</div></div>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <div class="rounded-lg border border-emerald-200 bg-emerald-50/20 p-2.5 text-center"><div class="text-base font-bold text-emerald-700">{{ $previewData['rt_valid'] ?? 0 }}</div><div class="text-[11px] text-gray-600 uppercase font-semibold">RT Sesuai Master</div></div>
                <div class="rounded-lg border border-rose-200 bg-rose-50/20 p-2.5 text-center"><div class="text-base font-bold text-rose-700">{{ $previewData['rt_invalid'] ?? 0 }}</div><div class="text-[11px] text-gray-600 uppercase font-semibold">RT Belum Ada</div></div>
                <div class="rounded-lg border border-indigo-200 bg-indigo-50/20 p-2.5 text-center"><div class="text-base font-bold text-indigo-700">{{ $previewData['rw_valid'] ?? 0 }}</div><div class="text-[11px] text-gray-600 uppercase font-semibold">RW Terhubung</div></div>
                <div class="rounded-lg border border-purple-200 bg-purple-50/20 p-2.5 text-center"><div class="text-base font-bold text-purple-700">{{ $previewData['rw_invalid'] ?? 0 }}</div><div class="text-[11px] text-gray-600 uppercase font-semibold">RW Belum Terhubung</div></div>
            </div>

            @if (count($previewData['errors'] ?? []) > 0)
                <div class="p-4 bg-amber-50 border border-amber-200 rounded-lg mb-4"><p class="text-amber-700 text-sm"><span class="font-medium">Peringatan:</span> {{ count($previewData['errors']) }} baris tidak valid tidak akan diimpor.</p></div>
            @endif
            <div class="p-4 bg-blue-50 border border-blue-200 rounded-lg"><p class="text-blue-700 text-sm"><span class="font-medium">Informasi:</span> KK baru akan dibuat otomatis dari nomor KK dan alamat di Excel tanpa menduplikasi KK untuk anggota keluarga yang sama.</p></div>
        </div>
    @endif
</div>
