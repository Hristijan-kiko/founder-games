<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log; // Import Log facade
use Illuminate\Http\Request;
use App\Models\Transcription;
use Illuminate\Support\Facades\Http;

class VideoTranscriptionController extends Controller
{
    public function showTranscribeForm()
    {
        return view('transcribe');
    }

    public function transcribe(Request $request)
    {
        // Validate the request
        $request->validate([
            'video_url' => 'required|url',
        ]);

        // Extract video ID from the YouTube URL
        $videoId = $this->extractVideoId($request->video_url);
        if (!$videoId) {
            return response()->json(['error' => 'Invalid YouTube URL'], 400);
        }

        // Fetch video details using YouTube Data API
        $apiKey = env('YOUTUBE_API_KEY'); // Your YouTube Data API key
        $videoDetailsUrl = "https://www.googleapis.com/youtube/v3/videos?id={$videoId}&key={$apiKey}&part=snippet,contentDetails";

        $videoDetailsResponse = Http::withOptions([
            'verify' => false, // Disable SSL verification
        ])->get($videoDetailsUrl);

        if ($videoDetailsResponse->failed()) {
            Log::error('Failed to fetch video details:', [
                'status' => $videoDetailsResponse->status(),
                'body' => $videoDetailsResponse->body(),
            ]);
            return response()->json(['error' => 'Failed to fetch video details'], 500);
        }

        $videoDetails = $videoDetailsResponse->json();
        if (empty($videoDetails['items'])) {
            return response()->json(['error' => 'Video not found'], 404);
        }

        // Example transcription API that accepts YouTube URLs
        $transcriptionApiUrl = 'https://api.example.com/transcribe'; // Replace with actual API URL
        $transcriptionResponse = Http::withOptions([
            'verify' => false, // Disable SSL verification
        ])->post($transcriptionApiUrl, [
            'youtube_url' => $request->video_url, // Use the YouTube video URL directly
        ]);

        if ($transcriptionResponse->failed()) {
            Log::error('Transcription request failed:', [
                'status' => $transcriptionResponse->status(),
                'body' => $transcriptionResponse->body(),
            ]);
            return response()->json(['error' => 'Failed to request transcription', 'details' => $transcriptionResponse->body()], 500);
        }

        $transcriptionId = $transcriptionResponse['id']; // Adjust based on actual response structure

        // Save the transcription request to the database
        $transcription = Transcription::create([
            'video_url' => $request->video_url,
            'transcription_id' => $transcriptionId,
            'status' => 'processing', // Transcription is being processed
        ]);

        return response()->json(['message' => 'Transcription request sent!', 'data' => $transcription]);
    }

    public function checkTranscriptionStatus()
    {
        // Fetch all transcriptions
        $transcriptions = Transcription::all();

        // Call the API to get the status for each transcription
        foreach ($transcriptions as $transcription) {
            // Call the transcription API to get the status
            $response = Http::withOptions([
                'verify' => false, // Disable SSL verification
            ])->get("https://api.example.com/transcribe/status/{$transcription->transcription_id}"); // Adjust based on actual API

            // Handle the response from the API
            if ($response->successful()) {
                $status = $response->json('status'); // Adjust based on actual response structure

                // Update transcription status in the database
                switch ($status) {
                    case 'completed':
                        $transcription->status = 'completed';
                        $transcription->text = $response->json('transcription_text'); // Save the transcription text
                        break;

                    case 'failed':
                        $transcription->status = 'failed';
                        break;

                        // Status could be 'processing', so no action needed
                }

                // Save the transcription status
                $transcription->save();
            } else {
                // Log or handle API error response
                $transcription->status = 'error'; // Mark as error if API call fails
                $transcription->save();
            }
        }

        // Pass the updated transcriptions to the view
        return view('status', compact('transcriptions'));
    }

    private function extractVideoId($url)
    {
        // Use regex to extract the video ID from the YouTube URL
        preg_match('/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^&\n]{11})/', $url, $matches);
        return isset($matches[1]) ? $matches[1] : null;
    }
}
