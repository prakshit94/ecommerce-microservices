<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds enterprise-grade fields to the user-service users table.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Organization & team
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');

            // Status & access control
            $table->enum('status', ['active', 'inactive', 'suspended', 'pending_verification'])
                ->default('active')->after('email');
            $table->boolean('is_super_admin')->default(false)->after('status');

            // Profile
            $table->string('phone', 30)->nullable()->after('password');
            $table->string('profile_photo_url')->nullable()->after('phone');
            $table->string('timezone', 64)->default('UTC')->after('profile_photo_url');
            $table->timestamp('last_login_at')->nullable()->after('timezone');

            // Foreign key (organizations table created in separate migration)
            // We add constraint after organizations table exists
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'organization_id',
                'status',
                'is_super_admin',
                'phone',
                'profile_photo_url',
                'timezone',
                'last_login_at',
            ]);
        });
    }
};
