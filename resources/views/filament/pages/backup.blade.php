<x-filament-panels::page>
    <div class="sipeta-backup-page" x-on:backup-restored.window="setTimeout(() => window.location.reload(), 1500)">
        {{-- Section 1: Google Drive Connection & Quick Actions --}}
        <div class="sipeta-backup-card">
            <div class="sipeta-backup-card-header">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Google Drive Cloud Backup</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Penyimpanan cadangan data SIPETA yang aman dan terpusat di Google Drive.</p>
                    </div>
                    @if (filled($googleDrive->google_drive_account_email))
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:border-emerald-800">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            Terhubung
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-800">
                            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                            Belum Terhubung
                        </span>
                    @endif
                </div>
            </div>

            <div class="p-6">
                @if (filled($googleDrive->google_drive_account_email))
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-xl bg-gray-50 dark:bg-zinc-800/50 border border-gray-200/80 dark:border-zinc-700">
                        <div class="space-y-1">
                            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Akun Google Drive</div>
                            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 00-9.78 2.096A4.001 4.001 0 003 15z"></path>
                                </svg>
                                {{ $googleDrive->google_drive_account_email }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Folder target: <span class="font-medium text-gray-700 dark:text-gray-300">SIPETA Backup</span></div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2.5">
                            <button
                                type="button"
                                class="sipeta-btn sipeta-btn-primary inline-flex items-center gap-2"
                                wire:click="createGoogleDriveBackup"
                                wire:loading.attr="disabled"
                                wire:target="createGoogleDriveBackup"
                            >
                                <svg wire:loading.remove wire:target="createGoogleDriveBackup" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                </svg>
                                <span wire:loading.remove wire:target="createGoogleDriveBackup">Backup Sekarang</span>
                                <span wire:loading wire:target="createGoogleDriveBackup" class="inline-flex items-center gap-2">
                                    <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Backup sedang diproses...
                                </span>
                            </button>

                            <button
                                type="button"
                                class="sipeta-btn sipeta-btn-secondary inline-flex items-center gap-1.5"
                                wire:click="syncDriveBackups"
                                wire:loading.attr="disabled"
                                wire:target="syncDriveBackups"
                            >
                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                </svg>
                                <span wire:loading.remove wire:target="syncDriveBackups">Sinkronkan</span>
                                <span wire:loading wire:target="syncDriveBackups">Sinkronisasi...</span>
                            </button>

                            <a
                                href="{{ route('admin.backup.download-local') }}"
                                class="sipeta-btn sipeta-btn-secondary inline-flex items-center gap-1.5"
                                title="Unduh arsip ZIP database & lampiran langsung ke komputer lokal"
                            >
                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                </svg>
                                Unduh ZIP Lokal
                            </a>

                            <button
                                type="button"
                                class="sipeta-btn sipeta-btn-secondary inline-flex items-center gap-1.5"
                                wire:click="testGoogleDriveConnection"
                                wire:loading.attr="disabled"
                                wire:target="testGoogleDriveConnection"
                            >
                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                Uji Koneksi
                            </button>

                            <button
                                type="button"
                                class="sipeta-btn sipeta-btn-outline-danger inline-flex items-center gap-1.5"
                                wire:click="disconnectGoogleDrive"
                                wire:loading.attr="disabled"
                                wire:target="disconnectGoogleDrive"
                            >
                                Putuskan
                            </button>
                        </div>
                    </div>

                    <div class="mt-3 p-3 rounded-lg bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 text-xs flex items-center gap-2" wire:loading wire:target="createGoogleDriveBackup">
                        <svg class="animate-spin h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>Sedang mengekspor database dan mengunggah backup ke Google Drive. Mohon tunggu dan jangan menutup halaman ini...</span>
                    </div>
                @else
                    <div class="text-center py-6 px-4 rounded-xl bg-gray-50 dark:bg-zinc-800/50 border border-dashed border-gray-300 dark:border-zinc-700">
                        <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-emerald-50 dark:bg-emerald-950 flex items-center justify-center text-emerald-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                            </svg>
                        </div>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Hubungkan Akun Google Drive</h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-md mx-auto">Untuk mengaktifkan fitur pencadangan otomatis dan pemulihan, hubungkan SIPETA dengan Google Drive Anda.</p>
                        <div class="mt-4 flex items-center justify-center gap-3">
                            <a class="sipeta-btn sipeta-btn-primary inline-flex items-center gap-2" href="{{ route('google-drive.connect') }}">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12.0003 2C6.4773 2 2.00031 6.477 2.00031 12C2.00031 17.523 6.4773 22 12.0003 22C17.5233 22 22.0003 17.523 22.0003 12C22.0003 6.477 17.5233 2 12.0003 2ZM17.2003 7.8L12.0003 16.8L6.80031 7.8H17.2003Z"/>
                                </svg>
                                Hubungkan Google Drive
                            </a>
                            <a class="sipeta-btn sipeta-btn-secondary inline-flex items-center gap-1.5" href="{{ route('admin.backup.download-local') }}" title="Unduh arsip cadangan SQLite & foto KK langsung ke komputer">
                                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                </svg>
                                Unduh Backup Lokal (ZIP)
                            </a>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Section 2: Riwayat Backup Table --}}
        <div class="sipeta-backup-card">
            <div class="sipeta-backup-card-header flex items-center justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Riwayat Backup Google Drive</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Daftar arsip cadangan yang tersimpan di Google Drive beserta metadata dan checksum.</p>
                </div>
                @if (!$driveBackups->isEmpty())
                    <span class="text-xs font-medium text-gray-500 bg-gray-100 dark:bg-zinc-800 px-2.5 py-1 rounded-md">
                        Total: {{ $driveBackups->count() }} backup
                    </span>
                @endif
            </div>

            @if ($driveBackups->isEmpty())
                <div class="sipeta-backup-empty py-12 text-center">
                    <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-gray-100 dark:bg-zinc-800 flex items-center justify-center text-gray-400">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                        </svg>
                    </div>
                    @if (filled($googleDrive->google_drive_account_email))
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Belum ada riwayat backup</p>
                        <p class="text-xs text-gray-400 mt-1">Klik tombol "Backup Sekarang" untuk membuat cadangan data pertama Anda di Google Drive.</p>
                    @else
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Belum terhubung ke Google Drive</p>
                        <p class="text-xs text-gray-400 mt-1">Hubungkan akun Google Drive terlebih dahulu untuk melihat dan mengelola cadangan cloud.</p>
                    @endif
                </div>
            @else
                <div class="sipeta-backup-table-wrapper">
                    <table class="sipeta-backup-table">
                        <thead>
                            <tr>
                                <th class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Nama File</th>
                                <th class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                <th class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Ukuran</th>
                                <th class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider">Waktu Backup</th>
                                <th class="text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wider text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-zinc-800">
                            @foreach ($driveBackups as $backup)
                                <tr class="hover:bg-emerald-50/40 dark:hover:bg-zinc-800/50 transition-colors">
                                    <td>
                                        <div class="flex items-center gap-2.5">
                                            <div class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                </svg>
                                            </div>
                                            <div>
                                                <span class="font-mono text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $backup->filename }}</span>
                                                @if($backup->checksum)
                                                    <div class="text-[11px] text-gray-400 font-mono truncate max-w-xs" title="{{ $backup->checksum }}">
                                                        {{ Str::limit($backup->checksum, 24) }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                            {{ $backup->backup_status->value === 'SUCCESS' ? 'Berhasil' : $backup->backup_status->value }}
                                        </span>
                                    </td>
                                    <td class="text-sm text-gray-600 dark:text-gray-400 font-medium">
                                        {{ number_format($backup->backup_size / 1024, 1, ',', '.') }} KB
                                    </td>
                                    <td class="text-sm text-gray-600 dark:text-gray-400">
                                        {{ $backup->started_at?->format('d M Y H:i') }}
                                    </td>
                                    <td>
                                        <div class="flex items-center justify-end gap-2">
                                            <a
                                                href="{{ route('admin.backup.download', $backup) }}"
                                                class="sipeta-btn sipeta-btn-sm sipeta-btn-secondary inline-flex items-center gap-1"
                                                title="Unduh file backup ZIP ke komputer"
                                            >
                                                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                                </svg>
                                                Unduh
                                            </a>
                                            <button
                                                type="button"
                                                class="sipeta-btn sipeta-btn-sm sipeta-btn-primary inline-flex items-center gap-1"
                                                wire:click="requestDriveRestore('{{ $backup->drive_file_id }}', '{{ addslashes($backup->filename) }}')"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                                </svg>
                                                Pulihkan
                                            </button>
                                            <button
                                                type="button"
                                                class="sipeta-btn sipeta-btn-sm sipeta-btn-outline-danger inline-flex items-center gap-1"
                                                wire:click="requestDriveDelete('{{ $backup->drive_file_id }}', '{{ addslashes($backup->filename) }}')"
                                            >
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                                Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Modal Restore Confirmation --}}
        @if ($driveRestoreCandidate !== null)
            <div class="sipeta-modal-overlay" wire:key="drive-restore-confirmation">
                <div class="sipeta-confirm-modal" role="dialog" aria-modal="true">
                    <div class="sipeta-confirm-icon sipeta-confirm-icon-warning">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                    </div>
                    <h2>Pulihkan Data dari Google Drive?</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Tindakan ini akan menggantikan seluruh database lokal saat ini dengan data dari file backup:</p>
                    <div class="sipeta-confirm-filename">{{ $driveRestoreFilename }}</div>
                    <p class="sipeta-confirm-warning">Integritas checksum dan manifest diverifikasi secara otomatis sebelum restore dijalankan.</p>

                    <div wire:loading wire:target="confirmDriveRestore" class="mt-4 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800/60 text-amber-800 dark:text-amber-200 text-xs flex items-center justify-center gap-2">
                        <svg class="animate-spin h-4 w-4 flex-shrink-0 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="font-medium">Mengunduh dari Google Drive & memulihkan database... Mohon tunggu.</span>
                    </div>

                    <div class="sipeta-confirm-actions">
                        <button
                            type="button"
                            class="sipeta-btn sipeta-btn-secondary"
                            wire:click="cancelDriveRestore"
                            wire:loading.attr="disabled"
                            wire:target="confirmDriveRestore"
                        >
                            Batal
                        </button>
                        <button
                            type="button"
                            class="sipeta-btn sipeta-btn-primary inline-flex items-center gap-2"
                            wire:click="confirmDriveRestore"
                            wire:loading.attr="disabled"
                            wire:target="confirmDriveRestore"
                        >
                            <span wire:loading.remove wire:target="confirmDriveRestore">Konfirmasi Restore</span>
                            <span wire:loading wire:target="confirmDriveRestore" class="inline-flex items-center gap-1.5">
                                <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Memproses...
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        @endif

        {{-- Modal Delete Confirmation --}}
        @if ($driveDeleteCandidate !== null)
            <div class="sipeta-modal-overlay" wire:key="drive-delete-confirmation">
                <div class="sipeta-confirm-modal" role="dialog" aria-modal="true">
                    <div class="sipeta-confirm-icon sipeta-confirm-icon-danger">
                        <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </div>
                    <h2>Hapus File Backup?</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-300">File backup berikut akan dihapus secara permanen dari Google Drive:</p>
                    <div class="sipeta-confirm-filename">{{ $driveDeleteFilename }}</div>
                    <p class="sipeta-confirm-warning">Histori pencatatan di SIPETA hanya akan dihapus setelah Google Drive mengonfirmasi keberhasilan penghapusan.</p>
                    <div class="sipeta-confirm-actions">
                        <button
                            type="button"
                            class="sipeta-btn sipeta-btn-secondary"
                            wire:click="cancelDriveDelete"
                            wire:loading.attr="disabled"
                            wire:target="confirmDriveDelete"
                        >
                            Batal
                        </button>
                        <button
                            type="button"
                            class="sipeta-btn sipeta-btn-danger inline-flex items-center gap-2"
                            wire:click="confirmDriveDelete"
                            wire:loading.attr="disabled"
                            wire:target="confirmDriveDelete"
                        >
                            <span wire:loading.remove wire:target="confirmDriveDelete">Hapus Permanen</span>
                            <span wire:loading wire:target="confirmDriveDelete" class="inline-flex items-center gap-1.5">
                                <svg class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Menghapus...
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <style>
        .sipeta-backup-page { display: flex; flex-direction: column; gap: 1.5rem; width: 100%; }
        .sipeta-backup-card { width: 100%; border: 1px solid var(--sipeta-border, #e5e7eb); border-radius: 1rem; background: white; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        :where(.dark) .sipeta-backup-card { background: rgb(24 24 27); border-color: rgb(63 63 70); }
        .sipeta-backup-card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--sipeta-border, #e5e7eb); background: #fafbfc; }
        :where(.dark) .sipeta-backup-card-header { background: rgb(30 30 34); border-color: rgb(63 63 70); }
        .sipeta-backup-table-wrapper { width: 100%; overflow-x: auto; }
        .sipeta-backup-table { width: 100%; border-collapse: collapse; text-align: left; }
        .sipeta-backup-table th { padding: 0.85rem 1.25rem; background: #f8faf9; border-bottom: 1px solid var(--sipeta-border, #e5e7eb); font-size: 0.75rem; }
        :where(.dark) .sipeta-backup-table th { background: rgb(39 39 42); border-color: rgb(63 63 70); }
        .sipeta-backup-table td { padding: 0.9rem 1.25rem; border-bottom: 1px solid var(--sipeta-border, #f3f4f6); font-size: 0.875rem; vertical-align: middle; }
        :where(.dark) .sipeta-backup-table td { border-color: rgb(39 39 42); }

        .sipeta-btn { display: inline-flex; align-items: center; justify-content: center; font-size: 0.875rem; font-weight: 600; padding: 0.55rem 1rem; border-radius: 0.625rem; transition: all 150ms ease; cursor: pointer; border: 1px solid transparent; }
        .sipeta-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .sipeta-btn-sm { padding: 0.35rem 0.75rem; font-size: 0.8rem; border-radius: 0.5rem; }
        .sipeta-btn-primary { background: #456B4F; color: white; }
        .sipeta-btn-primary:hover:not(:disabled) { background: #385640; }
        .sipeta-btn-secondary { background: #f3f4f6; color: #374151; border-color: #e5e7eb; }
        .sipeta-btn-secondary:hover:not(:disabled) { background: #e5e7eb; }
        .sipeta-btn-danger { background: #dc2626; color: white; }
        .sipeta-btn-danger:hover:not(:disabled) { background: #b91c1c; }
        .sipeta-btn-outline-danger { background: transparent; color: #dc2626; border-color: #fecaca; }
        .sipeta-btn-outline-danger:hover:not(:disabled) { background: #fef2f2; border-color: #fca5a5; }

        .sipeta-modal-overlay { position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1.5rem; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px); }
        .sipeta-confirm-modal { width: min(100%, 460px); background: #fff; border-radius: 1.25rem; padding: 1.75rem; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25); text-align: center; }
        :where(.dark) .sipeta-confirm-modal { background: rgb(24 24 27); border: 1px solid rgb(63 63 70); }
        .sipeta-confirm-modal h2 { margin: 0 0 0.5rem; font-size: 1.25rem; font-weight: 700; color: #111827; }
        :where(.dark) .sipeta-confirm-modal h2 { color: #f9fafb; }
        .sipeta-confirm-icon { display: inline-flex; align-items: center; justify-content: center; width: 3.5rem; height: 3.5rem; border-radius: 9999px; margin-bottom: 1rem; }
        .sipeta-confirm-icon-danger { background: #fee2e2; color: #dc2626; }
        .sipeta-confirm-icon-warning { background: #fef3c7; color: #d97706; }
        .sipeta-confirm-filename { margin: 1rem 0; padding: 0.75rem 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.75rem; font-family: monospace; font-size: 0.85rem; font-weight: 600; word-break: break-all; color: #1e293b; }
        :where(.dark) .sipeta-confirm-filename { background: rgb(39 39 42); border-color: rgb(63 63 70); color: #f1f5f9; }
        .sipeta-confirm-warning { font-size: 0.75rem; color: #64748b; margin-top: 0.5rem; }
        .sipeta-confirm-actions { display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem; }
    </style>
</x-filament-panels::page>
