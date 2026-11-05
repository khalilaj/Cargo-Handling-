<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tanks', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('commodity');                       // cpo | cpol | pfad
            $t->unsignedBigInteger('capacity_kg');
            $t->unsignedBigInteger('opening_volume_kg')->default(0);
            $t->unsignedBigInteger('current_volume_kg')->default(0);
            $t->boolean('requires_heating')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tanks');
    }
};
