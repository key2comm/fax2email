<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sip_trunks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('enabled')->default(true);
            $table->string('auth_type');
            $table->string('transport');
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->json('extra_addresses')->nullable();
            $table->json('resolved_addresses')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolve_error')->nullable();
            $table->string('status')->nullable();
            $table->string('status_detail')->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->timestamps();
        });

        // Whitelist entries created for a trunk are removed with it.
        Schema::table('firewall_rules', function (Blueprint $table) {
            $table->foreignId('sip_trunk_id')->nullable()->after('jail')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('firewall_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sip_trunk_id');
        });
        Schema::dropIfExists('sip_trunks');
    }
};
