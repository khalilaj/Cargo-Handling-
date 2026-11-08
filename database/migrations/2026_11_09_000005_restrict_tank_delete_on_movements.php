<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** A tank with ledger rows must never be deletable: the rows would silently vanish with it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargo_movements', function ($t) {
            $t->dropForeign(['tank_id']);
            $t->foreign('tank_id')->references('id')->on('tanks')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cargo_movements', function ($t) {
            $t->dropForeign(['tank_id']);
            $t->foreign('tank_id')->references('id')->on('tanks')->cascadeOnDelete();
        });
    }
};
