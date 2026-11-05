<?php

namespace App\Domain\Fleet\Services;

use Illuminate\Support\Facades\DB;

/**
 * Builds register entries from the names typed into older voyages and orders and links the records.
 * Plain query-builder SQL on purpose: it runs inside a migration, before the models can be trusted.
 * Idempotent: records that already have a link are left alone.
 */
class RegisterBackfill
{
    /** @return array{vessels: int, customers: int} registers created */
    public static function run(): array
    {
        return [
            'vessels' => self::link('vessel_discharges', 'vessel_name', 'vessels', 'vessel_id'),
            'customers' => self::link('delivery_orders', 'customer', 'customers', 'customer_id'),
        ];
    }

    private static function link(string $table, string $nameColumn, string $registry, string $fk): int
    {
        $created = 0;
        $names = DB::table($table)->whereNull($fk)->whereNotNull($nameColumn)->distinct()->pluck($nameColumn);

        foreach ($names as $name) {
            $clean = trim((string) preg_replace('/\s+/', ' ', (string) $name));
            if ($clean === '') {
                continue;
            }

            $id = DB::table($registry)->where('name', $clean)->value('id');
            if ($id === null) {
                $id = DB::table($registry)->insertGetId(['name' => $clean, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $created++;
            }

            DB::table($table)->whereNull($fk)->where($nameColumn, $name)->update([$fk => $id]);
        }

        return $created;
    }
}
