<?php

namespace Database\Seeders;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use App\Enums\UserRole;
use App\Models\BackupLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data seeder untuk development/testing.
 *
 * Idempotent — aman dijalankan berulang kali tanpa menghapus data existing.
 * JANGAN dijalankan di production.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Pastikan ada Operator demo
        User::firstOrCreate(
            ['email' => 'operator@sipeta.test'],
            [
                'name' => 'Operator Demo',
                'role' => UserRole::OPERATOR,
                'password' => Hash::make('password'),
            ],
        );

        // Buat beberapa BackupLog dummy jika belum ada sama sekali
        if (BackupLog::count() === 0) {
            $files = [
                [
                    'filename' => 'sipeta_backup_2026_08_10_120000.zip',
                    'started_at' => now()->subDays(7),
                    'finished_at' => now()->subDays(7)->addSeconds(45),
                    'backup_size' => 1_024_000,
                    'drive_file_id' => 'demo_drive_id_001',
                    'drive_folder_id' => 'demo_folder_id',
                    'checksum' => 'sha256:'.sha1('demo_backup_001'),
                ],
                [
                    'filename' => 'sipeta_backup_2026_08_14_090000.zip',
                    'started_at' => now()->subDays(3),
                    'finished_at' => now()->subDays(3)->addSeconds(38),
                    'backup_size' => 1_198_336,
                    'drive_file_id' => 'demo_drive_id_002',
                    'drive_folder_id' => 'demo_folder_id',
                    'checksum' => 'sha256:'.sha1('demo_backup_002'),
                ],
                [
                    'filename' => 'sipeta_backup_2026_08_17_080000.zip',
                    'started_at' => now()->subHours(2),
                    'finished_at' => now()->subHours(2)->addSeconds(42),
                    'backup_size' => 1_250_000,
                    'drive_file_id' => 'demo_drive_id_003',
                    'drive_folder_id' => 'demo_folder_id',
                    'checksum' => 'sha256:'.sha1('demo_backup_003'),
                ],
            ];

            foreach ($files as $file) {
                BackupLog::create(array_merge($file, [
                    'backup_type' => BackupType::MANUAL,
                    'backup_status' => BackupStatus::SUCCESS,
                    'operator_id' => null,
                    'message' => null,
                ]));
            }
        }
    }
}
