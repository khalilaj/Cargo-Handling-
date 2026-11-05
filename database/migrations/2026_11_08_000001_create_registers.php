<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessels', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('imo', 7)->nullable()->unique();          // IMO number: the real-world identity of a ship
            $t->string('flag')->nullable();
            $t->string('owner_agent')->nullable();
            $t->unsignedBigInteger('capacity_kg')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index('name');
        });

        Schema::create('berths', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('contact_person')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('transporters', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('phone')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('trucks', function (Blueprint $t) {
            $t->id();
            $t->string('plate')->unique();                        // normalised: upper case, single spaces
            $t->foreignId('transporter_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('capacity_kg')->nullable();    // maximum payload
            $t->unsignedBigInteger('tare_kg')->nullable();        // empty weight
            $t->date('insurance_expiry')->nullable();
            $t->date('inspection_expiry')->nullable();           // roadworthiness
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('drivers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('licence_number')->unique();
            $t->date('licence_expiry')->nullable();
            $t->string('phone')->nullable();
            $t->foreignId('transporter_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['drivers', 'trucks', 'transporters', 'customers', 'berths', 'vessels'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
