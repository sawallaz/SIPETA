<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->string('google_drive_account_email')->nullable()->after('backup_path');
            $table->string('google_drive_folder_id')->nullable()->after('google_drive_account_email');
            $table->text('google_drive_credentials')->nullable()->after('google_drive_folder_id');
            $table->timestamp('google_drive_connected_at')->nullable()->after('google_drive_credentials');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn([
                'google_drive_account_email',
                'google_drive_folder_id',
                'google_drive_credentials',
                'google_drive_connected_at',
            ]);
        });
    }
};
