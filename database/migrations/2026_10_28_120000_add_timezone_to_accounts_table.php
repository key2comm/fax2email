<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The zone the date and time in the header of sent faxes is shown in.
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC')->after('name');
        });

        // Existing accounts start in the zone their first owner chose for themselves.
        DB::table('accounts')->orderBy('id')->each(function ($account) {
            $zone = DB::table('users')->where('account_id', $account->id)->where('role', 'account_owner')
                ->where('timezone', '!=', 'UTC')->orderBy('id')->value('timezone');
            if ($zone) {
                DB::table('accounts')->where('id', $account->id)->update(['timezone' => $zone]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
