<?php

use App\Http\Controllers\VideoTranscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/transcriptions', [VideoTranscriptionController::class, 'showTranscribeForm']);
Route::post('/transcribe', [VideoTranscriptionController::class, 'transcribe']);
Route::post('/transcriptions/search', [VideoTranscriptionController::class, 'searchTimestamps']);
Route::post('/transcriptions/update/{transcriptionId}', [VideoTranscriptionController::class, 'updateTranscription']);
