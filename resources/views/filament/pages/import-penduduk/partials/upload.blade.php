<div class="bg-white rounded-lg border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">1. Upload File Excel/CSV</h2>
    <p class="text-gray-600 mb-4">Pilih file Excel (.xlsx) atau CSV (.csv) yang berisi data penduduk. Maksimal 10 MB.</p>

    {{-- Loading state saat file sedang diupload/diparsing --}}
    <div wire:loading wire:target="file, uploadFile" class="mb-4 p-4 bg-primary/10 border border-primary/20 rounded-lg">
        <div class="flex items-center gap-3 text-primary font-medium text-sm">
            <x-filament::loading-indicator class="h-5 w-5 animate-spin" />
            <span>Membaca dan memproses file Excel... Mohon tunggu.</span>
        </div>
    </div>

    @if ($page->hasFileUploaded())
        <div wire:loading.remove wire:target="file, uploadFile" class="flex items-center justify-between p-4 bg-green-50 border border-green-200 rounded-lg mb-4">
            <div>
                <span class="text-green-700 font-medium">✓ File berhasil diunggah</span>
                <p class="text-sm text-green-600 mt-1">{{ $page->fileName }}</p>
            </div>
            <div class="flex gap-2">
                @if (count($page->sheets) > 1)
                    <x-filament::button type="button" color="primary" wire:click="goToSheet" wire:loading.attr="disabled">
                        Pilih Sheet
                    </x-filament::button>
                @else
                    <x-filament::button type="button" color="primary" wire:click="goToMapping" wire:loading.attr="disabled">
                        Lanjut ke Mapping
                    </x-filament::button>
                @endif
            </div>
        </div>
    @endif

    @if (!$page->hasFileUploaded())
        <form wire:submit="uploadFile" enctype="multipart/form-data" wire:loading.remove wire:target="file, uploadFile">
            <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-primary hover:bg-primary/5 transition cursor-pointer" onclick="document.getElementById('fileInput').click()">
                <x-filament::icon icon="heroicon-o-document-arrow-up" class="h-12 w-12 text-gray-400 mx-auto mb-4" />
                <p class="text-gray-600 font-medium mb-1">Klik atau seret file ke sini</p>
                <p class="text-xs text-gray-400 mb-4">Format didukung: .xlsx, .csv (maksimal 10 MB)</p>
                <input type="file" wire:model="file" accept=".xlsx,.csv" class="hidden" id="fileInput">
                <x-filament::button type="button" color="primary" onclick="event.stopPropagation(); document.getElementById('fileInput').click()">
                    Pilih File
                </x-filament::button>
            </div>
            @error('file') <p class="text-red-500 text-sm mt-2">{{ $message }}</p> @enderror
        </form>
    @endif
</div>
