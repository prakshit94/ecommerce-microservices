<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the audit_logs table for enterprise activity tracking.
 * Used by User model's auto-logging observer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index()->comment('Who performed the action');
            $table->string('event', 50)->comment('created, updated, deleted');
            $table->string('auditable_type')->comment('Model class name');
            $table->unsignedBigInteger('auditable_id')->comment('Model record ID');
            $table->json('old_values')->nullable()->comment('Previous values (for updates)');
            $table->json('new_values')->nullable()->comment('New/current values');
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
