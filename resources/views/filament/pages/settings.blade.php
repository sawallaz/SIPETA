<x-filament-panels::page>
    {{ $this->form }}

    <div class="fi-section">
        <x-filament::button
            wire:click="save"
            type="button"
            color="primary"
        >
            SIMPAN
        </x-filament::button>
    </div>
</x-filament-panels::page>