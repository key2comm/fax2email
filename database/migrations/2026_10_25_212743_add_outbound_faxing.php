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
        // A company-wide address book: everyone in the account shares it.
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('fax_number', 32);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'fax_number']);
        });

        // Which trunk a number's outgoing faxes use (null = the first enabled trunk).
        Schema::table('fax_numbers', function (Blueprint $table) {
            $table->foreignId('outbound_trunk_id')->nullable()->after('enabled')->constrained('sip_trunks')->nullOnDelete();
        });

        // How a provider expects dialled numbers: 15551234567, +15551234567 or 5551234567.
        Schema::table('sip_trunks', function (Blueprint $table) {
            $table->string('dial_format')->default('e164')->after('port');
        });

        Schema::table('faxes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('fax_number_id')->constrained()->nullOnDelete();
            $table->string('source')->nullable()->after('direction');
            $table->string('sender_email')->nullable()->after('user_id');
            $table->string('destination_number', 32)->nullable()->after('called_number');
            $table->string('destination_name')->nullable()->after('destination_number');
            $table->unsignedSmallInteger('attempts')->default(0)->after('pages');
            $table->timestamp('next_attempt_at')->nullable()->after('attempts');
            $table->string('hangup_cause')->nullable()->after('result_text');
            $table->timestamp('completed_at')->nullable()->after('emailed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('faxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['source', 'sender_email', 'destination_number', 'destination_name', 'attempts', 'next_attempt_at', 'hangup_cause', 'completed_at']);
        });
        Schema::table('sip_trunks', fn (Blueprint $table) => $table->dropColumn('dial_format'));
        Schema::table('fax_numbers', fn (Blueprint $table) => $table->dropConstrainedForeignId('outbound_trunk_id'));
        Schema::dropIfExists('contacts');
    }
};
