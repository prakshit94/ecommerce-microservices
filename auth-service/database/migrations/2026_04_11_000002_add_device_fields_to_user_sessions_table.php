<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds device & client tracking fields to user_sessions table.
     */
    public function up(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            // Device & client type
            $table->string('device_type', 20)->default('web')->after('user_agent');
            // web | ios | android | api | desktop
            $table->string('device_name', 100)->nullable()->after('device_type');
            $table->string('device_fingerprint', 128)->nullable()->after('device_name');

            // Geo & trust
            $table->string('country_code', 5)->nullable()->after('device_fingerprint');
            $table->boolean('is_trusted_device')->default(false)->after('country_code');

            // MFA info
            $table->boolean('mfa_verified')->default(false)->after('is_trusted_device');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'device_type',
                'device_name',
                'device_fingerprint',
                'country_code',
                'is_trusted_device',
                'mfa_verified',
            ]);
        });
    }
};
