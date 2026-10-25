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
        // The firewall's desired state. The root-owned fax2email-firewall-apply
        // service reads it and applies it to nftables and fail2ban.
        Schema::create('firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('cidr', 64);
            $table->string('source')->default('manual');
            $table->string('jail')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('unban_requested_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'cidr', 'jail']);
            $table->index('expires_at');
        });

        Schema::create('firewall_events', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->string('cidr', 64)->index();
            $table->string('jail')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('firewall_events');
        Schema::dropIfExists('firewall_rules');
    }
};
