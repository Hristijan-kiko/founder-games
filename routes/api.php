<?php

use App\Http\Controllers\VideoTranscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/transcriptions', [VideoTranscriptionController::class, 'showTranscribeForm']);
Route::post('/transcribe', [VideoTranscriptionController::class, 'transcribe']);
Route::post('/transcriptions/search', [VideoTranscriptionController::class, 'searchTimestamps']);
Route::post('/transcriptions/update/{transcriptionId}', [VideoTranscriptionController::class, 'updateTranscription']);
Route::get('/transcriptions/{id}', [VideoTranscriptionController::class, 'getTranscription']); // New route
Route::post('/parse-prompt', [VideoTranscriptionController::class, 'parsePrompt']);
// Route::post('/gpt-response', [VideoTranscriptionController::class, 'getGPTResponseAPI']);
Route::get('/transcriptions/{id}', [VideoTranscriptionController::class, 'showSingleTranscription']);
