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
            $table->string('transport', 32)->default('server')->after('file_checksum');
            // The remote multipart upload id (e.g. the S3 UploadId).
            $table->string('remote_upload_id')->nullable()->after('transport');
        });
    }
};
