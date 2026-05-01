<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the chunked_uploads.batch_id → chunky_batches.batch_id foreign
     * key. Lets the cleanup sweep null out the relationship instead of
     * leaving orphan rows when a batch row is hard-deleted (a future
     * feature; today the package never deletes batch rows itself, but
     * an operator running their own retention SQL might).
     *
     * The constraint uses `nullOnDelete()` (NOT cascade) on purpose —
     * orphaning the per-file uploads under their original IDs preserves
     * the audit trail for forensics, and the cleanup command will
     * collect the rows on its next sweep.
     */
    public function up(): void
    {
        // SQLite cannot add a foreign key constraint after the table is
        // created (it would require rebuilding the entire table, which
        // Schema::table doesn't generate). Skip the migration on SQLite
        // — the FK is most useful on production-grade engines anyway,
        // where ON DELETE SET NULL is honoured natively.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        Schema::table('chunked_uploads', function (Blueprint $table) {
            $table->foreign('batch_id')
                ->references('batch_id')
                ->on('chunky_batches')
                ->nullOnDelete();
        });
    }
};
