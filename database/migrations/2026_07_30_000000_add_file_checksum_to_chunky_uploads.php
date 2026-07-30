<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chunky_uploads', function (Blueprint $table): void {
            // Whole-file SHA-256 hex reported by the client (64 chars).
            $table->string('file_checksum', 64)->nullable()->after('fingerprint');
        });
    }
};
