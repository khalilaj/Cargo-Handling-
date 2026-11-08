<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tanks are archived like the other registers: switched off, deleted only afterwards by an admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tanks', function (Blueprint $t) {
            $t->boolean('active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('tanks', function (Blueprint $t) {
            $t->dropColumn('active');
        });
    }
};
