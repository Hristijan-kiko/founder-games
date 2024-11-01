<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\VideoTranscriptionController;

Route::get('/transcribe', [VideoTranscriptionController::class, 'showTranscribeForm']);
Route::post('/transcribe', [VideoTranscriptionController::class, 'transcribe']);
Route::get('/transcriptions/check-status', [VideoTranscriptionController::class, 'checkTranscriptionStatus']);
