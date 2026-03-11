<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chunked_uploads')) {
            return;
        }

        Schema::create('chunked_uploads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('upload_id')->unique()->index();
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->json('uploaded_chunks')->default('[]');
            $table->string('disk');
            $table->string('context')->nullable()->index();
            $table->string('final_path')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }
};
