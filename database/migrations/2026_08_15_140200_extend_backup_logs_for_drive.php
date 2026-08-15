<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_logs', function (Blueprint $table): void {
            $table->string('drive_file_id')->nullable()->after('filename');
            $table->string('drive_folder_id')->nullable()->after('drive_file_id');
            $table->string('checksum', 64)->nullable()->after('backup_size');
            $table->index('drive_file_id', 'backup_logs_drive_file_id_index');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('backup_logs', function (Blueprint $table): void {
                $table->string('backup_status', 20)->change();
            });
        } else {
            Schema::table('backup_logs', function (Blueprint $table): void {
                $table->enum('backup_status', ['PENDING', 'RUNNING', 'SUCCESS', 'FAILED'])->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('backup_logs', function (Blueprint $table): void {
            $table->dropIndex('backup_logs_drive_file_id_index');
            $table->dropColumn(['drive_file_id', 'drive_folder_id', 'checksum']);
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('backup_logs', function (Blueprint $table): void {
                $table->string('backup_status', 20)->change();
            });
        } else {
            Schema::table('backup_logs', function (Blueprint $table): void {
                $table->enum('backup_status', ['SUCCESS', 'FAILED'])->change();
            });
        }
    }
};
