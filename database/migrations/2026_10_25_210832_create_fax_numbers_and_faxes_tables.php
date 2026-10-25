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
        // Phone numbers (DIDs) that receive faxes, each belonging to one account.
        Schema::create('fax_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32)->unique();
            $table->string('label')->nullable();
            $table->json('recipients')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('faxes', function (Blueprint $table) {
            $table->id();
            $table->uuid('call_uuid')->nullable()->unique();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('fax_number_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction')->default('inbound');
            $table->string('status');
            $table->string('called_number', 32)->nullable();
            $table->string('caller_number', 64)->nullable();
            $table->string('caller_name')->nullable();
            $table->string('remote_station_id')->nullable();
            $table->unsignedSmallInteger('pages')->default(0);
            $table->string('result_text')->nullable();
            $table->string('tiff_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->text('email_error')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faxes');
        Schema::dropIfExists('fax_numbers');
    }
};
