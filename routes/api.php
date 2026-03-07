<?php

use Illuminate\Support\Facades\Route;
use NETipar\Chunky\Http\Controllers\InitiateUploadController;
use NETipar\Chunky\Http\Controllers\UploadChunkController;
use NETipar\Chunky\Http\Controllers\UploadStatusController;
use NETipar\Chunky\Http\Middleware\VerifyChunkIntegrity;

Route::post('upload', InitiateUploadController::class)->name('chunky.initiate');
Route::post('upload/{uploadId}/chunks', UploadChunkController::class)
    ->middleware(VerifyChunkIntegrity::class)
    ->name('chunky.chunk');
Route::get('upload/{uploadId}', UploadStatusController::class)->name('chunky.status');
