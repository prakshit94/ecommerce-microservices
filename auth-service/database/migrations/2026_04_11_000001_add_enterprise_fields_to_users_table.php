<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds enterprise-grade fields to the auth-service users table.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Account status & locking
            $table->boolean('is_active')->default(true)->after('password');
            $table->timestamp('locked_until')->nullable()->after('is_active');
            $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('locked_until');

            // Multi-Factor Authentication
            $table->boolean('mfa_enabled')->default(false)->after('failed_login_attempts');
            $table->string('mfa_secret')->nullable()->after('mfa_enabled');
            $table->json('mfa_backup_codes')->nullable()->after('mfa_secret');
            $table->boolean('mfa_verified')->default(false)->after('mfa_backup_codes');

            // User metadata
            $table->timestamp('last_login_at')->nullable()->after('mfa_verified');
            $table->string('timezone', 64)->default('UTC')->after('last_login_at');

            // Email verification (enforcement)
            // Note: email_verified_at already exists in the base table
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_active',
                'locked_until',
                'failed_login_attempts',
                'mfa_enabled',
                'mfa_secret',
                'mfa_backup_codes',
                'mfa_verified',
                'last_login_at',
                'timezone',
            ]);
        });
    }
};
