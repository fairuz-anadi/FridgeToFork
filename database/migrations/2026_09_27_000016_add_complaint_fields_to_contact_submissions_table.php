<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact messages become complaints the admin can work through: who sent
 * it, what it is about, whether it is dealt with, and the admin's reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('category')->default('other')->after('email');
            $table->string('status')->default('open')->after('message');
            $table->text('admin_reply')->nullable()->after('status');
            $table->timestamp('resolved_at')->nullable()->after('admin_reply');
        });
    }

    public function down(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['category', 'status', 'admin_reply', 'resolved_at']);
        });
    }
};
