<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truck_visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('truck_id')->constrained()->restrictOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('delivery_order_id')->constrained()->restrictOnDelete();
            $t->string('status')->default('arrived')->index();
            // Holds the truck's id while the visit is open and NULL once it is closed. UNIQUE, so the
            // database itself guarantees "one open visit per truck" (many NULLs are allowed).
            $t->unsignedBigInteger('active_truck_id')->nullable()->unique();
            $t->dateTime('gate_in_at');
            $t->dateTime('weighed_in_at')->nullable();
            $t->unsignedBigInteger('weigh_in_kg')->nullable();       // empty weight on arrival
            $t->dateTime('loading_started_at')->nullable();
            $t->dateTime('weighed_out_at')->nullable();
            $t->unsignedBigInteger('weigh_out_kg')->nullable();      // loaded weight on departure
            $t->dateTime('gate_out_at')->nullable();
            $t->json('warnings')->nullable();                        // compliance and weighbridge warnings (never blocks)
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::table('cargo_movements', function (Blueprint $t) {
            $t->foreignId('truck_visit_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cargo_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('truck_visit_id');
        });
        Schema::dropIfExists('truck_visits');
    }
};
