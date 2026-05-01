<?php

use Illuminate\Support\Facades\Route;
use NETipar\Chunky\Http\Controllers\BatchStatusController;
use NETipar\Chunky\Http\Controllers\CancelUploadController;
use NETipar\Chunky\Http\Controllers\InitiateBatchController;
use NETipar\Chunky\Http\Controllers\InitiateBatchUploadController;
use NETipar\Chunky\Http\Controllers\InitiateUploadController;
use NETipar\Chunky\Http\Controllers\UploadChunkController;
use NETipar\Chunky\Http\Controllers\UploadStatusController;
use NETipar\Chunky\Http\Middleware\VerifyChunkIntegrity;

Route::post('upload', InitiateUploadController::class)->name('chunky.initiate');
Route::post('upload/{uploadId}/chunks', UploadChunkController::class)
    ->middleware(VerifyChunkIntegrity::class)
    ->name('chunky.chunk');
Route::get('upload/{uploadId}', UploadStatusController::class)->name('chunky.status');
Route::delete('upload/{uploadId}', CancelUploadController::class)->name('chunky.cancel');

Route::post('batch', InitiateBatchController::class)->name('chunky.batch.initiate');
Route::post('batch/{batchId}/upload', InitiateBatchUploadController::class)->name('chunky.batch.upload');
Route::get('batch/{batchId}', BatchStatusController::class)->name('chunky.batch.status');
