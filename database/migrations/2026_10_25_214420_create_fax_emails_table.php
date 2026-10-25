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
        // Every email sent to <number>@<fax domain>, accepted or not, for the admin log.
        Schema::create('fax_emails', function (Blueprint $table) {
            $table->id();
            $table->string('from_address')->nullable();
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->string('client_ip', 64)->nullable();
            $table->string('spf', 20)->nullable();
            $table->string('dkim_domain')->nullable();
            $table->string('status');
            $table->string('reason')->nullable();
            $table->foreignId('fax_id')->nullable()->constrained('faxes')->nullOnDelete();
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fax_emails');
    }
};
