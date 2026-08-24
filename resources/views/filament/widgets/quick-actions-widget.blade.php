<x-filament-widgets::widget class="fi-wi-quick-actions">
    <x-filament::section
        heading="Akses Cepat"
        description="Kelola dan lihat data kependudukan SIPETA"
    >
        <div class="fi-wi-quick-actions-grid">
            @foreach ($actions as $action)
                <a
                    href="{{ $action['url'] }}"
                    class="fi-wi-quick-actions-item group"
                >
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-400 group-hover:bg-emerald-100 transition-colors flex-shrink-0">
                        <x-filament::icon
                            :icon="$action['icon']"
                            class="w-5 h-5"
                        />
                    </div>

                    <div class="fi-wi-quick-actions-body flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-1">
                            <span class="fi-wi-quick-actions-label">{{ $action['label'] }}</span>
                            <svg class="w-4 h-4 text-gray-400 group-hover:text-emerald-600 transition-transform group-hover:translate-x-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </div>
                        <span class="fi-wi-quick-actions-description">{{ $action['description'] }}</span>
                    </div>
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
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 0.75rem;
            }

            .fi-wi-quick-actions-item {
                display: flex;
                align-items: center;
                gap: 0.75rem;
                min-width: 0;
                padding: 0.875rem;
                border: 1px solid rgb(229 231 235);
                border-radius: 0.625rem;
                text-decoration: none;
                transition:
                    background-color 0.15s ease,
                    border-color 0.15s ease,
                    transform 0.15s ease;
            }

            .fi-wi-quick-actions-item:hover {
                background-color: rgb(247 249 247);
                border-color: rgb(163 177 138);
                transform: translateY(-1px);
            }

            .fi-wi-quick-actions-icon {
                flex: none;
                width: 1.5rem;
                height: 1.5rem;
                color: rgb(76 99 56);
            }

            @media (max-width: 1024px) {
                .fi-wi-quick-actions-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 640px) {
                .fi-wi-quick-actions-grid {
                    grid-template-columns: 1fr;
                }
            }

            :where(.dark) .fi-wi-quick-actions-item {
                border-color: rgb(63 63 70);
            }

            :where(.dark) .fi-wi-quick-actions-item:hover {
                background-color: rgb(39 49 35);
                border-color: rgb(82 102 61);
            }

            :where(.dark) .fi-wi-quick-actions-icon {
                color: rgb(138 168 59);
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
