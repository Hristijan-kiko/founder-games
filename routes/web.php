<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoTranscriptionController;

Route::get('/', function () {
    return view('welcome');
});

// // Transcription routes
// Route::get('/transcribe', [VideoTranscriptionController::class, 'showTranscribeForm'])->name('transcribe');
// Route::post('/transcribe', [VideoTranscriptionController::class, 'transcribe']);
// Route::post('/transcribe/search', [VideoTranscriptionController::class, 'searchTimestamps'])->name('search.timestamps');
// Route::get('/transcriptions/check-status', [VideoTranscriptionController::class, 'checkTranscriptionStatus']);
// Route::post('/transcriptions/{transcriptionId}/parse', [VideoTranscriptionController::class, 'parseTranscription']);
// Route::post('/summarize-transcription', [VideoTranscriptionController::class, 'summarizeTranscription'])->name('summarize.transcription');


// // New route for ChatGPT prompt
// Route::post('/transcriptions/prompt', [VideoTranscriptionController::class, 'parsePrompt'])->name('parse.prompt');

// // Existing route for parsing transcription (if needed)
// Route::post('/transcriptions/{transcriptionId}/parse', [VideoTranscriptionController::class, 'parseTranscription']);
