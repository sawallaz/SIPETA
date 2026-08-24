<x-filament-panels::page>
    <div class="mx-auto w-full max-w-4xl">
        {{-- Step Indicator --}}
        <div class="flex items-center mb-8">
            @php
                $steps = ['upload', 'sheet', 'mapping', 'preview', 'import', 'result'];
                $stepLabels = ['Upload File', 'Pilih Sheet', 'Mapping', 'Preview', 'Import', 'Hasil'];
                $page = $this;
                $currentIndex = array_search($page->currentStep, $steps, true);
            @endphp
            @foreach ($steps as $i => $step)
                @php
                    $stepTextClass = $i <= $currentIndex ? 'text-primary' : 'text-gray-400';
                    $stepIconClass = $i <= $currentIndex
                        ? 'h-8 w-8 rounded-full flex items-center justify-center text-primary bg-primary/10'
                        : 'h-8 w-8 rounded-full flex items-center justify-center text-gray-300';
                    $connectorClass = $i < $currentIndex ? 'bg-primary' : 'bg-gray-200';
                @endphp
                <div class="flex items-center flex-1">
                    <div class="flex flex-col items-center {{ $stepTextClass }}">
                        <x-filament::icon
                            icon="heroicon-o-minus-circle" 
                            class="{{ $stepIconClass }}"
                        />
                        <span class="text-xs mt-1 font-medium">{{ $stepLabels[$i] }}</span>
                    </div>
                    @if ($i < count($steps) - 1)
                        <div class="flex-1 h-0.5 mx-2 {{ $connectorClass }} rounded"></div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Step Content --}}
        <div class="mb-6">
            @if ($page->currentStep === 'upload')
                @include('filament.pages.import-penduduk.partials.upload')
            @elseif ($page->currentStep === 'sheet')
                @include('filament.pages.import-penduduk.partials.sheet')
            @elseif ($page->currentStep === 'mapping')
                @include('filament.pages.import-penduduk.partials.mapping')
            @elseif ($page->currentStep === 'preview')
                @include('filament.pages.import-penduduk.partials.preview')
            @elseif ($page->currentStep === 'import')
                @include('filament.pages.import-penduduk.partials.import')
            @elseif ($page->currentStep === 'result')
                @include('filament.pages.import-penduduk.partials.import')
            @endif
        </div>

        {{-- Footer Actions --}}
        <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-gray-200">
            @if ($page->currentStep !== 'upload')
                <x-filament::button 
                    type="button" 
                    variant="outline" 
                    color="gray"
                    icon="heroicon-o-arrow-left"
                    wire:click="cancelImport"
                >
                    Batal
                </x-filament::button>
            @endif

            @if ($page->currentStep === 'sheet')
                <x-filament::button 
                    type="button" 
                    color="primary"
                    icon="heroicon-o-arrow-right"
                    wire:click="mapColumns"
                >
                    Lanjut ke Mapping
                </x-filament::button>
            @elseif ($page->currentStep === 'mapping')
                <x-filament::button 
                    type="button" 
                    color="primary"
                    icon="heroicon-o-arrow-right"
                    wire:click="confirmMapping"
                >
                    Lanjut ke Preview
                </x-filament::button>
            @elseif ($page->currentStep === 'preview')
                <x-filament::button 
                    type="button" 
                    color="primary"
                    icon="heroicon-o-arrow-right"
                    wire:click="prepareImport"
                >
                    Lanjut ke Import
                </x-filament::button>
            @elseif ($page->currentStep === 'import')
                <x-filament::button 
                    type="button" 
                    color="primary"
                    icon="heroicon-o-check"
                    wire:click="importData"
                >
                    Impor Data
                </x-filament::button>
            @elseif ($page->currentStep === 'result')
                <x-filament::button 
                    type="button" 
                    color="primary"
                    icon="heroicon-o-arrow-path"
                    wire:click="cancelImport"
                >
                    Import File Lain
                </x-filament::button>
            @endif
        </div>
    </div>
</x-filament-panels::page>
