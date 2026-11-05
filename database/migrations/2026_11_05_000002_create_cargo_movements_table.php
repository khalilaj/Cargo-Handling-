<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cargo_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tank_id')->constrained()->cascadeOnDelete();
            $t->string('type');                            // vessel_discharge | truck_delivery
            $t->string('reference')->nullable();           // vessel name / truck plate
            $t->unsignedBigInteger('gross_kg');
            $t->unsignedBigInteger('loss_kg')->default(0);
            $t->unsignedBigInteger('net_kg');
            $t->dateTime('occurred_at', 6);
            $t->string('idempotency_key')->unique();       // blocks duplicate submissions
            $t->timestamps();
            $t->index(['tank_id', 'occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargo_movements');
    }
};
