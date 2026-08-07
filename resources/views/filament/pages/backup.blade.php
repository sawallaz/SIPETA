<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Buat Backup
        </x-slot>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Arsip ZIP menyimpan database, gambar KK, dan pengaturan aplikasi.
            Membuat backup tidak pernah menghapus backup sebelumnya.
        </p>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
            Klik <b>Buat Backup</b> di bagian atas halaman untuk membuat arsip baru.
        </p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            Daftar Backup
        </x-slot>

        @if ($backups->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Belum ada backup. Buat backup pertama dengan tombol di atas.
            </p>
        @else
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($backups as $backup)
                    <div class="flex items-center justify-between gap-4 py-3">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                {{ $backup['filename'] }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ number_format($backup['size'] / 1024, 1, ',', '.') }} KB
                                •
                                {{ \Illuminate\Support\Carbon::createFromTimestamp($backup['lastModified'])->format('d/m/Y H:i') }}
                            </div>
                        </div>
                        <x-filament::button
                            type="button"
                            color="gray"
                            wire:click="requestRestore('{{ $backup['filename'] }}')"
                        >
                            Pulihkan
                        </x-filament::button>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    @if ($this->restoreCandidate !== null)
        <x-filament::section>
            <div class="space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        Konfirmasi Pemulihan
                    </h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Pulihkan data dari
                        <strong>{{ $this->restoreCandidate }}</strong>?
                        Pemulihan bisa menggantikan data saat ini dan tidak dapat dibatalkan.
                    </p>
                </div>

                <div class="flex gap-3">
                    <x-filament::button
                        type="button"
                        color="gray"
                        wire:click="cancelRestore"
                    >
                        Batal
                    </x-filament::button>
                    <x-filament::button
                        type="button"
                        color="danger"
                        wire:click="confirmRestore"
                    >
                        Konfirmasi
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>