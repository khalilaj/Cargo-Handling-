<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One counter per kind of number (voyage per month, orders, tanks per product, berths), handed out under a row lock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_sequences', function (Blueprint $t) {
            $t->string('name', 60)->primary();
            $t->unsignedBigInteger('last_value')->default(0);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "code_sequences" ENABLE ROW LEVEL SECURITY');   // see the row-level security migration
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('code_sequences');
    }
};
