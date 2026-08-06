<x-filament-widgets::widget class="fi-wi-quick-actions">
    <x-filament::section
        heading="Aksi Cepat"
        description="Akses cepat ke data kependudukan"
    >
        <div class="fi-wi-quick-actions-grid">
            @foreach ($actions as $action)
                <a
                    href="{{ $action['url'] }}"
                    class="fi-wi-quick-actions-item"
                >
                    <x-filament::icon
                        :icon="$action['icon']"
                        class="fi-wi-quick-actions-icon"
                    />

                    <span class="fi-wi-quick-actions-body">
                        <span class="fi-wi-quick-actions-label">{{ $action['label'] }}</span>
                        <span class="fi-wi-quick-actions-description">{{ $action['description'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

@once
    @push('styles')
        <style>
            .fi-wi-quick-actions-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
                gap: 0.75rem;
            }

            .fi-wi-quick-actions-item {
                display: flex;
                align-items: center;
                gap: 0.75rem;
                padding: 0.75rem;
                border: 1px solid rgb(229 231 235);
                border-radius: 0.5rem;
                text-decoration: none;
                transition: background-color 0.15s, border-color 0.15s;
            }

            .fi-wi-quick-actions-item:hover {
                background-color: rgb(249 250 251);
                border-color: rgb(209 213 219);
            }

            :where(.dark) .fi-wi-quick-actions-item {
                border-color: rgb(63 63 70);
            }

            :where(.dark) .fi-wi-quick-actions-item:hover {
                background-color: rgb(39 39 42);
                border-color: rgb(82 82 91);
            }

            .fi-wi-quick-actions-icon {
                flex: none;
                width: 1.5rem;
                height: 1.5rem;
                color: rgb(156 163 175);
            }

            :where(.dark) .fi-wi-quick-actions-icon {
                color: rgb(113 113 122);
            }

            .fi-wi-quick-actions-body {
                display: flex;
                flex-direction: column;
                gap: 0.125rem;
                min-width: 0;
            }

            .fi-wi-quick-actions-label {
                font-size: 0.875rem;
                font-weight: 500;
                line-height: 1.25rem;
                color: rgb(24 24 27);
            }

            :where(.dark) .fi-wi-quick-actions-label {
                color: rgb(250 250 250);
            }

            .fi-wi-quick-actions-description {
                font-size: 0.75rem;
                line-height: 1rem;
                color: rgb(107 114 128);
            }

            :where(.dark) .fi-wi-quick-actions-description {
                color: rgb(161 161 170);
            }
        </style>
    @endpush
@endonce
