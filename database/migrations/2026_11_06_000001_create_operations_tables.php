<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vessel_discharges', function (Blueprint $t) {
            $t->id();
            $t->string('voyage_ref')->unique();
            $t->string('vessel_name');
            $t->string('commodity');                         // cpo | cpol | pfad
            $t->unsignedBigInteger('bl_quantity_kg');        // bill of lading quantity
            $t->string('status')->default('scheduled');
            $t->dateTime('eta')->nullable();
            $t->dateTime('berthed_at')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('delivery_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_ref')->unique();
            $t->string('customer');
            $t->string('commodity');
            $t->unsignedBigInteger('ordered_kg');
            $t->string('status')->default('pending');
            $t->dateTime('scheduled_at')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
        });

        Schema::table('cargo_movements', function (Blueprint $t) {
            $t->foreignId('vessel_discharge_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('delivery_order_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cargo_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vessel_discharge_id');
            $t->dropConstrainedForeignId('delivery_order_id');
        });
        Schema::dropIfExists('delivery_orders');
        Schema::dropIfExists('vessel_discharges');
    }
};
