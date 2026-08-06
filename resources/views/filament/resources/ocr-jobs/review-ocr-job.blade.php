<x-filament-panels::page>
    @php($this->ensureParsed())

    @if ($this->rejectedReason !== null)
        <x-filament::section>
            <x-slot name="heading">
                Hasil OCR belum dapat direview
            </x-slot>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $this->rejectedReason }}
            </p>
        </x-filament::section>
    @else
        {{ $this->form }}

        <div class="fi-section">
            <x-filament::button
                wire:click="validateReview"
                type="button"
                color="primary"
            >
                Validasi Data
            </x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
