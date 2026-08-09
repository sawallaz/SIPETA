<x-filament-panels::page>

    {{-- Root Alpine scope: holds modal visibility + selected filename.
         The actual delete/restore still run in Livewire/PHP (Backup.php);
         Alpine only toggles the modal UI. --}}
    <div
        class="sipeta-backup-page"
        x-data="{ open: false, action: null, filename: '' }"
    >

        {{-- =========================================================
             STAT CARDS  (uses existing $backups collection)
        ========================================================== --}}
        <div class="sipeta-backup-stats">

            {{-- TOTAL BACKUP --}}
            <div class="sipeta-backup-stat">
                <span>Total Backup</span>
                <strong>{{ $backups->count() }}</strong>
                <small>Arsip backup tersedia</small>
            </div>

            {{-- PENYIMPANAN (static label — no such variable in Backup.php) --}}
            <div class="sipeta-backup-stat">
                <span>Penyimpanan</span>
                <strong>Database SIPETA</strong>
                <small>Lokasi penyimpanan backup aktif</small>
            </div>

            {{-- STATUS SSTEM (static label) --}}
            <div class="sipeta-backup-stat">
                <span>Status Sistem</span>
                <strong class="sipeta-status-ok">Aktif</strong>
                <small>Backup siap digunakan</small>
            </div>

        </div>


        {{-- =========================================================
             DAFTAR BACKUP
        ========================================================== --}}
        <div class="sipeta-backup-card">

            <div class="sipeta-backup-card-header">
                <h3>Daftar Backup</h3>
                <p>Backup database SIPETA yang tersedia untuk dipulihkan atau dihapus.</p>
            </div>


            @if ($backups->isEmpty())

                {{-- EMPTY STATE --}}
                <div class="sipeta-backup-empty">
                    <div class="sipeta-backup-empty-icon">
                        <x-filament::icon icon="heroicon-o-archive-box" />
                    </div>
                    <strong>Belum ada backup</strong>
                    <p>
                        Gunakan tombol <b>Buat Backup</b> di bagian atas
                        untuk membuat arsip database baru.
                    </p>
                </div>

            @else

                <div class="sipeta-backup-table-wrapper">
                    <table class="sipeta-backup-table">

                        <thead>
                            <tr>
                                <th>Nama File</th>
                                <th>Ukuran</th>
                                <th>Dibuat</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>

                        <tbody>

                            @foreach ($backups as $backup)

                                <tr>
                                    {{-- NAMA FILE --}}
                                    <td>
                                        <div class="sipeta-file-name">
                                            <x-filament::icon
                                                icon="heroicon-o-document-arrow-down"
                                            />
                                            <span>{{ $backup['filename'] }}</span>
                                        </div>
                                    </td>

                                    {{-- UKURAN --}}
                                    <td>
                                        {{ number_format($backup['size'] / 1024, 1, ',', '.') }}
                                        KB
                                    </td>

                                    {{-- TANGGAL --}}
                                    <td>
                                        {{ date('d M Y H:i', $backup['lastModified']) }}
                                    </td>

                                    {{-- AKSI --}}
                                    <td>
                                        <div
                                            class="sipeta-backup-actions"
                                            style="margin-top: 0; justify-content: flex-end;"
                                        >
                                            {{-- PULIHKAN --}}
                                            <button
                                                type="button"
                                                class="sipeta-btn sipeta-btn-primary"
                                                x-on:click="
                                                    filename = '{{ $backup['filename'] }}';
                                                    action = 'restore';
                                                    $wire.requestRestore(filename);
                                                    open = true;
                                                "
                                            >
                                                Pulihkan
                                            </button>

                                            {{-- HAPUS --}}
                                            <button
                                                type="button"
                                                class="sipeta-btn sipeta-btn-danger"
                                                x-on:click="
                                                    filename = '{{ $backup['filename'] }}';
                                                    action = 'delete';
                                                    $wire.requestDelete(filename);
                                                    open = true;
                                                "
                                            >
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


        {{-- =========================================================
             MODAL HAPUS  (centered overlay, client-side Alpine state)
        ========================================================== --}}
        <div
            x-show="open && action === 'delete'"
            x-cloak
            x-transition.opacity
            class="sipeta-modal-overlay"
            x-on:keydown.escape.window="open = false; $wire.cancelDelete();"
            x-on:click.self="open = false; $wire.cancelDelete();"
        >

            <div class="sipeta-confirm-modal" x-on:click.stop>

                <div class="sipeta-confirm-icon sipeta-confirm-icon-danger">
                    <x-filament::icon icon="heroicon-o-trash" />
                </div>

                <h2>Hapus Backup?</h2>

                <p>Kamu akan menghapus file backup berikut secara permanen:</p>

                <div class="sipeta-confirm-filename" x-text="filename"></div>

                <p class="sipeta-confirm-warning">
                    File akan dihapus dari penyimpanan backup.
                    Histori audit backup tetap dipertahankan.
                </p>

                <div class="sipeta-confirm-actions">
                    <button
                        type="button"
                        class="sipeta-btn sipeta-btn-secondary"
                        x-on:click="open = false; $wire.cancelDelete();"
                    >
                        Batal
                    </button>

                    <button
                        type="button"
                        class="sipeta-btn sipeta-btn-danger"
                        x-on:click="open = false; $wire.confirmDelete();"
                    >
                        Hapus Backup
                    </button>
                </div>

            </div>

        </div>


        {{-- =========================================================
             MODAL RESTORE  (same pattern, amber accent)
        ========================================================== --}}
        <div
            x-show="open && action === 'restore'"
            x-cloak
            x-transition.opacity
            class="sipeta-modal-overlay"
            x-on:keydown.escape.window="open = false; $wire.cancelRestore();"
            x-on:click.self="open = false; $wire.cancelRestore();"
        >

            <div class="sipeta-confirm-modal" x-on:click.stop>

                <div class="sipeta-confirm-icon sipeta-confirm-icon-warning">
                    <x-filament::icon icon="heroicon-o-arrow-path" />
                </div>

                <h2>Pulihkan Backup?</h2>

                <p>
                    Data SIPETA akan dipulihkan dari backup ini.
                    Pastikan file backup yang dipilih benar.
                </p>

                <div class="sipeta-confirm-filename" x-text="filename"></div>

                <p class="sipeta-confirm-warning">
                    Pemulihan akan mengganti data aplikasi dengan isi backup.
                </p>

                <div class="sipeta-confirm-actions">
                    <button
                        type="button"
                        class="sipeta-btn sipeta-btn-secondary"
                        x-on:click="open = false; $wire.cancelRestore();"
                    >
                        Batal
                    </button>

                    <button
                        type="button"
                        class="sipeta-btn sipeta-btn-primary"
                        x-on:click="open = false; $wire.confirmRestore();"
                    >
                        Pulihkan
                    </button>
                </div>

            </div>

        </div>

    </div>


    {{-- =========================================================
         MODAL-SPECIFIC CSS (scoped). The page layout itself
         relies on the already-compiled .sipeta-backup-* rules
         in resources/css/sipeta-admin.css.
    ========================================================== --}}
    <style>
        .sipeta-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15, 23, 42, 0.55);
        }

        .sipeta-confirm-modal {
            width: min(100%, 460px);
            background: #ffffff;
            border-radius: 18px;
            padding: 26px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.20);
            text-align: center;
        }

        .sipeta-confirm-modal h2 {
            margin: 0 0 8px;
            font-size: 20px;
            font-weight: 700;
            color: #111827;
        }

        .sipeta-confirm-modal p {
            color: #667085;
            line-height: 1.5;
            margin: 0;
        }

        .sipeta-confirm-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            border-radius: 9999px;
            margin-bottom: 14px;
        }

        .sipeta-confirm-icon svg {
            width: 28px;
            height: 28px;
        }

        .sipeta-confirm-icon-danger {
            background: #fee2e2;
            color: #dc2626;
        }

        .sipeta-confirm-icon-warning {
            background: #fef3c7;
            color: #f59e0b;
        }

        .sipeta-confirm-filename {
            margin: 16px 0;
            padding: 12px 14px;
            background: #f5f7f6;
            border: 1px solid #e1e7e3;
            border-radius: 10px;
            font-weight: 600;
            word-break: break-word;
            color: #1f2937;
        }

        .sipeta-confirm-warning {
            margin-top: 12px;
            font-size: 13px;
        }

        .sipeta-confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
        }

        .sipeta-btn {
            border: 0;
            border-radius: 10px;
            padding: 10px 16px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
        }

        .sipeta-btn-secondary {
            background: #f2f4f3;
            color: #344054;
        }

        .sipeta-btn-danger {
            background: #dc2626;
            color: #ffffff;
        }

        .sipeta-btn-primary {
            background: #f5b000;
            color: #1f2937;
        }

        .sipeta-status-ok {
            color: #16a34a;
        }

        [x-cloak] {
            display: none !important;
        }

        @media (max-width: 640px) {
            .sipeta-confirm-actions {
                flex-direction: column-reverse;
            }

            .sipeta-confirm-actions .sipeta-btn {
                width: 100%;
            }
        }
    </style>

</x-filament-panels::page>
