<div class="bg-white rounded-lg border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">1. Upload File Excel/CSV</h2>
    <p class="text-gray-600 mb-4">Pilih file Excel (.xlsx) atau CSV (.csv) yang berisi data penduduk. Maksimal 10 MB.</p>

    @if ($page->hasFileUploaded())
        <div class="flex items-center justify-between p-4 bg-green-50 border border-green-200 rounded-lg mb-4">
            <div>
                <span class="text-green-700 font-medium">✓ File berhasil diunggah</span>
                <p class="text-sm text-green-600 mt-1">{{ $page->fileName }}</p>
            </div>
            <div class="flex gap-2">
                @if (count($page->sheets) > 1)
                    <x-filament::button type="button" color="primary" wire:click="goToSheet">
                        Pilih Sheet
                    </x-filament::button>
                @else
                    <x-filament::button type="button" color="primary" wire:click="goToMapping">
                        Lanjut ke Mapping
                    </x-filament::button>
                @endif
            </div>
        </div>
    @endif

    @if (!$page->hasFileUploaded())
        <form wire:submit="uploadFile" enctype="multipart/form-data">
            <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-primary hover:bg-primary/5 transition">
                <x-filament::icon icon="heroicon-o-document-arrow-up" class="h-12 w-12 text-gray-400 mx-auto mb-4" />
                <p class="text-gray-600 mb-2">Klik atau seret file ke sini</p>
                <p class="text-sm text-gray-400 mb-4">Format: .xlsx, .csv (maksimal 10 MB)</p>
                <input type="file" wire:model="file" accept=".xlsx,.csv" class="hidden" id="fileInput">
                <x-filament::button type="button" color="primary" onclick="document.getElementById('fileInput').click()">
                    Pilih File
                </x-filament::button>
            </div>
            @error('file') <p class="text-red-500 text-sm mt-2">{{ $message }}</p> @enderror
        </form>
    @endif
</div>
