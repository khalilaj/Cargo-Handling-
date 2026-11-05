<?php

use App\Domain\Fleet\Services\RegisterBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vessel_discharges', function (Blueprint $t) {
            $t->foreignId('vessel_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('berth_id')->nullable()->constrained()->restrictOnDelete();
            // Hold the berth / vessel id only while the ship is alongside (berthed or discharging), NULL otherwise.
            // UNIQUE, so the database itself guarantees one ship per berth and one berth per ship.
            $t->unsignedBigInteger('active_berth_id')->nullable()->unique();
            $t->unsignedBigInteger('active_vessel_id')->nullable()->unique();
        });

        Schema::table('delivery_orders', function (Blueprint $t) {
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
        });

        // Existing records keep working: build the registers from the names already typed in
        // and link every voyage / order to its register entry.
        RegisterBackfill::run();

        // Ships already alongside: record the guard columns (first come, first served if a vessel appears twice).
        $seenVessels = [];
        foreach (DB::table('vessel_discharges')->whereIn('status', ['berthed', 'discharging'])->orderBy('id')->get() as $row) {
            $vesselOk = $row->vessel_id && ! in_array($row->vessel_id, $seenVessels, true);
            $seenVessels[] = $row->vessel_id;
            DB::table('vessel_discharges')->where('id', $row->id)->update([
                'active_berth_id' => $row->berth_id,
                'active_vessel_id' => $vesselOk ? $row->vessel_id : null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('customer_id');
        });
        Schema::table('vessel_discharges', function (Blueprint $t) {
            $t->dropUnique(['active_berth_id']);
            $t->dropUnique(['active_vessel_id']);
            $t->dropColumn(['active_berth_id', 'active_vessel_id']);
            $t->dropConstrainedForeignId('berth_id');
            $t->dropConstrainedForeignId('vessel_id');
        });
    }
};
