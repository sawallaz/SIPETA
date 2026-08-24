<x-filament-widgets::widget class="fi-wi-stats-overview">
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">

        @foreach ($stats as $index => $stat)
            @php
                $style = [
                    'accent' => 'bg-[#365C45]',
                    'iconBg' => 'bg-[#365C45]/10 dark:bg-[#365C45]/20',
                    'iconColor' => 'text-[#365C45] dark:text-[#8AA83B]',
                ];
            @endphp

            <div
                class="group relative overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-gray-700 dark:bg-gray-900"
            >

                {{-- Accent line --}}
                <div class="absolute inset-x-0 top-0 h-1 {{ $style['accent'] }}"></div>

                <div class="p-5">

                    {{-- Header --}}
                    <div class="flex items-start justify-between gap-4">

                        {{-- Label + Value --}}
                        <div class="min-w-0">

                            <p class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">
                                {{ $stat->getLabel() }}
                            </p>

                            <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">
                                {{ $stat->getValue() }}
                            </p>

                        </div>

                        {{-- Icon --}}
                        @if ($stat->getIcon())
                            <div
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl {{ $style['iconBg'] }} transition-transform duration-200 group-hover:scale-105"
                            >
                                <x-filament::icon
                                    :icon="$stat->getIcon()"
                                    class="h-6 w-6 {{ $style['iconColor'] }}"
                                />
                            </div>
                        @endif

                    </div>

                    {{-- Description --}}
                    @if ($stat->getDescription())
                        <div class="mt-4 flex items-center gap-2">

                            @if ($stat->getDescriptionIcon())
                                <x-filament::icon
                                    :icon="$stat->getDescriptionIcon()"
                                    class="h-4 w-4 shrink-0 {{ $style['iconColor'] }}"
                                />
                            @endif

                            <span class="truncate text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ $stat->getDescription() }}
                            </span>

                        </div>
                    @endif

                </div>

            </div>
        @endforeach

    </div>
</x-filament-widgets::widget>
