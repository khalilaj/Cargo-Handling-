<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vessel_discharges', function (Blueprint $t) {
            $t->text('completion_note')->nullable();   // why a voyage was closed with cargo still on the ship
        });
    }

    public function down(): void
    {
        Schema::table('vessel_discharges', function (Blueprint $t) {
            $t->dropColumn('completion_note');
        });
    }
};
