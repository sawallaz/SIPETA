<x-filament-widgets::widget class="fi-wi-recent-activity">
    <x-filament::section
        heading="Aktivitas Terbaru"
        description="Kartu keluarga dan penduduk terbaru di sistem"
    >
        @if ($activities->isEmpty())
            <x-filament::empty-state
                heading="Belum ada aktivitas"
                description="Kartu keluarga dan penduduk yang baru ditambahkan akan muncul di sini."
                icon="heroicon-o-clock"
            />
        @else
            <div class="fi-wi-recent-activity-list">
                @foreach ($activities as $activity)
                    <a
                        href="{{ $activity['url'] }}"
                        class="fi-wi-recent-activity-item"
                    >
                        <x-filament::icon
                            :icon="$activity['icon']"
                            class="fi-wi-recent-activity-icon"
                        />

                        <span class="fi-wi-recent-activity-body">
                            <span class="fi-wi-recent-activity-title">{{ $activity['title'] }}</span>
                            <span class="fi-wi-recent-activity-subtitle">{{ $activity['subtitle'] }}</span>
                        </span>

                        <time
                            class="fi-wi-recent-activity-time"
                            datetime="{{ $activity['created_at']->toIso8601String() }}"
                        >{{ $activity['created_at']->locale('id')->diffForHumans() }}</time>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

@once
    @push('styles')
        <style>
            .fi-wi-recent-activity-list {
                display: flex;
                flex-direction: column;
            }

            .fi-wi-recent-activity-item {
                display: flex;
                align-items: flex-start;
                gap: 0.75rem;
                padding: 0.625rem 0.25rem;
                border-radius: 0.5rem;
                text-decoration: none;
                transition: background-color 0.15s;
            }

            .fi-wi-recent-activity-item:hover {
                background-color: rgb(249 250 251);
            }

            :where(.dark) .fi-wi-recent-activity-item:hover {
                background-color: rgb(39 39 42);
            }

            .fi-wi-recent-activity-icon {
                flex: none;
                width: 1.25rem;
                height: 1.25rem;
                margin-top: 0.125rem;
                color: rgb(156 163 175);
            }

            :where(.dark) .fi-wi-recent-activity-icon {
                color: rgb(113 113 122);
            }

            .fi-wi-recent-activity-body {
                display: flex;
                flex-direction: column;
                gap: 0.125rem;
                min-width: 0;
            }

            .fi-wi-recent-activity-title {
                font-size: 0.875rem;
                font-weight: 500;
                line-height: 1.25rem;
                color: rgb(24 24 27);
            }

            :where(.dark) .fi-wi-recent-activity-title {
                color: rgb(250 250 250);
            }

            .fi-wi-recent-activity-subtitle {
                font-size: 0.75rem;
                line-height: 1rem;
                color: rgb(107 114 128);
            }

            :where(.dark) .fi-wi-recent-activity-subtitle {
                color: rgb(161 161 170);
            }

            .fi-wi-recent-activity-time {
                flex: none;
                margin-left: auto;
                padding-top: 0.25rem;
                font-size: 0.75rem;
                line-height: 1rem;
                white-space: nowrap;
                color: rgb(156 163 175);
            }

            :where(.dark) .fi-wi-recent-activity-time {
                color: rgb(113 113 122);
            }
        </style>
    @endpush
@endonce
