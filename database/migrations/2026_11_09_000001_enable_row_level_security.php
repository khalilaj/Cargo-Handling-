<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supabase exposes every table in the public schema through its Data API (PostgREST).
 * This app talks to the database only through Laravel (as the owner role, which bypasses
 * row-level security), so turning RLS on with NO policies shuts the public API door completely
 * while changing nothing for the application. PostgreSQL only; SQLite has no such thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->each(fn (string $table) => DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY"));
    }

    public function down(): void
    {
        $this->each(fn (string $table) => DB::statement("ALTER TABLE \"{$table}\" DISABLE ROW LEVEL SECURITY"));
    }

    private function each(callable $fn): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (DB::select('select tablename from pg_tables where schemaname = current_schema()') as $row) {
            $fn($row->tablename);
        }
    }
};
