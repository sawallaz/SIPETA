<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Morphic audit trail (who/when/what/old/new).
     * No hard FK — audit must outlive the row it describes.
     * Written by Laravel Model Observers in the application phase (Q3).
     * Append-only by design (created_at only).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('loggable_type');
            $table->unsignedBigInteger('loggable_id');
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event'); // created, updated, status_changed, restored
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at');
            $table->index(['loggable_type', 'loggable_id']);
            $table->index('actor_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
