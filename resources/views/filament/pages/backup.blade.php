<x-filament-panels::page>
    <div class="sipeta-backup-page">
        <div class="sipeta-backup-card">
            <div class="sipeta-backup-card-header">
                <h3>Google Drive</h3>
                <p>Backup cloud hanya dapat dikelola oleh Super Admin.</p>
            </div>

            @if (filled($googleDrive->google_drive_account_email))
                <div class="sipeta-drive-connection">
                    <strong>Terhubung sebagai: {{ $googleDrive->google_drive_account_email }}</strong>
                    <span class="sipeta-status-ok">✓ Terhubung</span>
                    <span>Folder: SIPETA Backup</span>

                    <div class="sipeta-backup-actions">
                        <button
                            type="button"
                            class="sipeta-btn sipeta-btn-primary"
                            wire:click="createGoogleDriveBackup"
                            wire:loading.attr="disabled"
                            wire:target="createGoogleDriveBackup"
                        >
                            <span wire:loading.remove wire:target="createGoogleDriveBackup">Backup Sekarang</span>
                            <span wire:loading wire:target="createGoogleDriveBackup">Backup sedang diproses...</span>
                        </button>
                        <button
                            type="button"
                            class="sipeta-btn sipeta-btn-secondary"
                            wire:click="testGoogleDriveConnection"
                            wire:loading.attr="disabled"
                            wire:target="testGoogleDriveConnection"
                        >
                            Uji Koneksi
                        </button>
                        <button
                            type="button"
                            class="sipeta-btn sipeta-btn-danger"
                            wire:click="disconnectGoogleDrive"
                            wire:loading.attr="disabled"
                            wire:target="disconnectGoogleDrive"
                        >
                            Putuskan
                        </button>
                    </div>

                    <div class="sipeta-backup-progress" wire:loading wire:target="createGoogleDriveBackup">
                        Backup sedang diproses. Jangan tutup halaman ini.
                    </div>
                </div>
            @else
                <div class="sipeta-drive-connection">
                    <strong>Belum terhubung</strong>
                    <a class="sipeta-btn sipeta-btn-primary" href="{{ route('google-drive.connect') }}">
                        Hubungkan Google Drive
                    </a>
                </div>
            @endif
        </div>

        <div class="sipeta-backup-card">
            <div class="sipeta-backup-card-header">
                <h3>Riwayat Backup Google Drive</h3>
                <p>Metadata upload, checksum, ukuran, dan waktu backup tersimpan sebagai histori.</p>
            </div>

            @if ($driveBackups->isEmpty())
                <p>Belum ada backup Google Drive.</p>
            @else
                <div class="sipeta-backup-table-wrapper">
                    <table class="sipeta-backup-table">
                        <thead>
                            <tr>
                                <th>Nama File</th>
                                <th>Status</th>
                                <th>Ukuran</th>
                                <th>Waktu</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($driveBackups as $backup)
                                <tr>
                                    <td>{{ $backup->filename }}</td>
                                    <td>{{ $backup->backup_status->value }}</td>
                                    <td>{{ number_format($backup->backup_size / 1024, 1, ',', '.') }} KB</td>
                                    <td>{{ $backup->started_at?->format('d M Y H:i') }}</td>
                                    <td>
                                        <div class="sipeta-backup-actions sipeta-backup-actions-compact">
                                            <button
                                                type="button"
                                                class="sipeta-btn sipeta-btn-primary"
                                                wire:click="requestDriveRestore('{{ $backup->drive_file_id }}', '{{ addslashes($backup->filename) }}')"
                                            >
                                                Pulihkan
                                            </button>
                                            <button
                                                type="button"
                                                class="sipeta-btn sipeta-btn-danger"
                                                wire:click="requestDriveDelete('{{ $backup->drive_file_id }}', '{{ addslashes($backup->filename) }}')"
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

        @if ($driveRestoreCandidate !== null)
            <div class="sipeta-modal-overlay" wire:key="drive-restore-confirmation">
                <div class="sipeta-confirm-modal" role="dialog" aria-modal="true">
                    <div class="sipeta-confirm-icon sipeta-confirm-icon-warning">↻</div>
                    <h2>Pulihkan Backup Google Drive?</h2>
                    <p>Restore akan mengganti data SIPETA dengan backup {{ $driveRestoreFilename }}.</p>
                    <p class="sipeta-confirm-warning">Checksum dan manifest diverifikasi sebelum restore.</p>
                    <div class="sipeta-confirm-actions">
                        <button type="button" class="sipeta-btn sipeta-btn-secondary" wire:click="cancelDriveRestore">Batal</button>
                        <button type="button" class="sipeta-btn sipeta-btn-primary" wire:click="confirmDriveRestore">Konfirmasi Restore</button>
                    </div>
                </div>
            </div>
        @endif

        @if ($driveDeleteCandidate !== null)
            <div class="sipeta-modal-overlay" wire:key="drive-delete-confirmation">
                <div class="sipeta-confirm-modal" role="dialog" aria-modal="true">
                    <div class="sipeta-confirm-icon sipeta-confirm-icon-danger">×</div>
                    <h2>Hapus Backup Google Drive?</h2>
                    <p>File berikut akan dihapus dari Google Drive:</p>
                    <div class="sipeta-confirm-filename">{{ $driveDeleteFilename }}</div>
                    <p class="sipeta-confirm-warning">Histori di SIPETA hanya dihapus setelah Google Drive mengonfirmasi keberhasilan.</p>
                    <div class="sipeta-confirm-actions">
                        <button type="button" class="sipeta-btn sipeta-btn-secondary" wire:click="cancelDriveDelete">Batal</button>
                        <button type="button" class="sipeta-btn sipeta-btn-danger" wire:click="confirmDriveDelete">Hapus</button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <style>
        .sipeta-drive-connection { display: grid; gap: 10px; }
        .sipeta-backup-progress { color: #667085; font-size: 13px; }
        .sipeta-backup-actions-compact { margin-top: 0; }
        .sipeta-modal-overlay { position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 24px; background: rgba(15, 23, 42, .55); }
        .sipeta-confirm-modal { width: min(100%, 460px); background: #fff; border-radius: 18px; padding: 26px; box-shadow: 0 25px 60px rgba(0, 0, 0, .2); text-align: center; }
        .sipeta-confirm-modal h2 { margin: 0 0 8px; font-size: 20px; font-weight: 700; color: #111827; }
        .sipeta-confirm-modal p { color: #667085; line-height: 1.5; margin: 0; }
        .sipeta-confirm-icon { display: inline-flex; align-items: center; justify-content: center; width: 56px; height: 56px; border-radius: 9999px; margin-bottom: 14px; font-size: 30px; font-weight: 700; }
        .sipeta-confirm-icon-danger { background: #fee2e2; color: #dc2626; }
        .sipeta-confirm-icon-warning { background: #fef3c7; color: #f59e0b; }
        .sipeta-confirm-filename { margin: 16px 0; padding: 12px 14px; background: #f5f7f6; border: 1px solid #e1e7e3; border-radius: 10px; font-weight: 600; word-break: break-word; color: #1f2937; }
        .sipeta-confirm-warning { margin-top: 12px !important; font-size: 13px; }
        .sipeta-confirm-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }
        .sipeta-btn { border: 0; border-radius: 10px; padding: 10px 16px; font-weight: 600; cursor: pointer; font-size: 14px; }
        .sipeta-btn:disabled { opacity: .6; cursor: wait; }
        .sipeta-btn-secondary { background: #f2f4f3; color: #344054; }
        .sipeta-btn-danger { background: #dc2626; color: #fff; }
        .sipeta-btn-primary { background: #f5b000; color: #1f2937; }
        .sipeta-status-ok { color: #16a34a; }
        @media (max-width: 640px) { .sipeta-confirm-actions { flex-direction: column-reverse; } .sipeta-confirm-actions .sipeta-btn { width: 100%; } }
    </style>
</x-filament-panels::page>