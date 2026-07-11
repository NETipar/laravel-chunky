<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Both tables in one migration, no cross-table foreign key: batch_id on
        // chunky_uploads is a plain indexed column. This avoids the migration
        // ordering problem on FK-enforcing databases (0.x v0.22.4).
        Schema::create('chunky_batches', function (Blueprint $table): void {
            $table->uuid('batch_id')->primary();
            $table->unsignedInteger('total_files');
            $table->unsignedInteger('completed_files')->default(0);
            $table->unsignedInteger('failed_files')->default(0);
            $table->string('status')->index();
            $table->string('profile')->nullable();
            $table->json('metadata')->nullable();
            $table->string('user_id')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('chunky_uploads', function (Blueprint $table): void {
            $table->uuid('upload_id')->primary();
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->string('disk');
            $table->string('profile')->nullable();
            $table->json('metadata')->nullable();
            $table->json('uploaded_chunks')->nullable();
            $table->string('status')->index();
            $table->string('final_path')->nullable();
            $table->uuid('batch_id')->nullable()->index();
            $table->string('user_id')->nullable();
            $table->string('fingerprint')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->json('result_payload')->nullable();
            $table->timestamps();

            $table->index(['fingerprint', 'user_id']);
        });
    }
};
