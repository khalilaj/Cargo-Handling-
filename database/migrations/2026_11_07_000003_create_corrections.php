<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cargo_movements', function (Blueprint $t) {
            // A movement can be reversed at most once: the UNIQUE is the database-level guarantee.
            $t->foreignId('reverses_movement_id')->nullable()->unique()->constrained('cargo_movements')->restrictOnDelete();
        });

        Schema::create('correction_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cargo_movement_id')->constrained()->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->string('status')->default('pending')->index();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->foreignId('reversal_movement_id')->nullable()->constrained('cargo_movements')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correction_requests');
        Schema::table('cargo_movements', function (Blueprint $t) {
            $t->dropUnique(['reverses_movement_id']);          // SQLite cannot drop an indexed column
            $t->dropConstrainedForeignId('reverses_movement_id');
        });
    }
};
