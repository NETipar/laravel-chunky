<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chunky_batches')) {
            return;
        }

        Schema::create('chunky_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('batch_id')->unique()->index();
            // See create_chunked_uploads_table for the rationale.
            $table->string('user_id')->nullable()->index();
            $table->unsignedInteger('total_files');
            $table->unsignedInteger('completed_files')->default(0);
            $table->unsignedInteger('failed_files')->default(0);
            $table->string('context')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }
};
