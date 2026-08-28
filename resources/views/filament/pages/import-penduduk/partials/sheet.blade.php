<div class="bg-white rounded-lg border border-gray-200 p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">2. Pilih Sheet</h2>
    <p class="text-gray-600 mb-4">Pilih sheet dari file yang diunggah:</p>

    {{-- Loading state saat sheet sedang dibaca --}}
    <div wire:loading wire:target="selectSheet" class="mb-4 p-4 bg-primary/10 border border-primary/20 rounded-lg">
        <div class="flex items-center gap-3 text-primary font-medium text-sm">
            <x-filament::loading-indicator class="h-5 w-5 animate-spin" />
            <span>Membaca data sheet... Mohon tunggu.</span>
        </div>
    </div>

    @if (count($page->sheets) === 0)
        <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
            <p class="text-yellow-700">Tidak ada sheet yang ditemukan.</p>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($page->sheets as $index => $sheetName)
                @php
                    $isSelected = $page->selectedSheetName === $sheetName;
                    $sheetButtonClass = $isSelected
                        ? 'bg-primary/5 border-primary'
                        : 'border-gray-200 hover:bg-gray-50';
                    $sheetIconClass = $isSelected ? 'h-5 w-5 text-primary' : 'h-5 w-5 text-gray-300';
                @endphp
                <button type="button" 
                    wire:click="selectSheet({{ $index }})"
                    wire:loading.attr="disabled"
                    wire:target="selectSheet"
                    class="w-full text-left p-3 rounded-lg border transition {{ $sheetButtonClass }} disabled:opacity-50"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-medium text-gray-900">{{ $sheetName }}</span>
                            <p class="text-sm text-gray-500">Sheet #{{ $index + 1 }}</p>
                        </div>
                        <x-filament::icon
                            icon="heroicon-o-check-circle" 
                            class="{{ $sheetIconClass }}"
                        />
                    </div>
                </button>
            @endforeach
        </div>
    @endif
</div>
