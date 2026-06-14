<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evidence_items') || ! Schema::hasTable('suggested_records')) {
            return;
        }

        match (DB::getDriverName()) {
            'pgsql' => $this->dropPostgresNotNull(),
            'mysql', 'mariadb' => $this->dropMysqlNotNull(),
            default => null,
        };
    }

    public function down(): void
    {
        // Intentionally not reversible: existing standalone finding evidence may
        // have null investigation_id after this migration.
    }

    private function dropPostgresNotNull(): void
    {
        DB::statement('ALTER TABLE evidence_items ALTER COLUMN investigation_id DROP NOT NULL');
        DB::statement('ALTER TABLE suggested_records ALTER COLUMN investigation_id DROP NOT NULL');
    }

    private function dropMysqlNotNull(): void
    {
        DB::statement('ALTER TABLE evidence_items MODIFY investigation_id CHAR(36) NULL');
        DB::statement('ALTER TABLE suggested_records MODIFY investigation_id CHAR(36) NULL');
    }
};
