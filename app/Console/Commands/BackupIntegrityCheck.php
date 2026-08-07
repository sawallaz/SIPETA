<?php

namespace App\Console\Commands;

use App\Services\BackupIntegrityResult;
use App\Services\BackupIntegrityService;
use Illuminate\Console\Command;

/**
 * Phase 6.6 — Backup integrity check on launch (FR-MED-04 / F-MED-04).
 *
 * Runs the read-only `BackupIntegrityService` over every archive on the
 * `db_backups` disk and prints its health. This is the natural "on launch"
 * entry point for the desktop-delivered application: running it at startup
 * surfaces a corrupted or incomplete backup before the operator relies on it
 * (NFR-REL-01). The command exits non-zero when any archive is UNHEALTHY, so
 * a launch script / scheduler can react to a detected integrity problem.
 */
class BackupIntegrityCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:integrity-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Periksa integritas setiap arsip backup (FR-MED-04)';

    public function handle(BackupIntegrityService $service): int
    {
        $results = $service->checkAll();

        if ($results === []) {
            $this->info('Belum ada arsip backup untuk diperiksa.');

            return self::SUCCESS;
        }

        $rows = array_map(
            fn (BackupIntegrityResult $result) => [
                $result->filename,
                $result->isOk() ? 'SEHAT' : 'RUSAK',
                implode("\n", $result->issues) ?: '-',
            ],
            $results,
        );

        $this->table(['Arsip', 'Status', 'Catatan'], $rows);

        $ok = count(array_filter($results, fn (BackupIntegrityResult $r) => $r->isOk()));
        $corrupt = count($results) - $ok;

        $this->newLine();
        $this->info(sprintf('%d arsip sehat, %d arsip bermasalah.', $ok, $corrupt));

        return $corrupt > 0 ? self::FAILURE : self::SUCCESS;
    }
}
