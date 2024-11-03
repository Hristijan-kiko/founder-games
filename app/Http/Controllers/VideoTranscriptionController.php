<?php

namespace App\Http\Controllers;

use App\Models\Transcription; // Ensure you have the Transcription model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VideoTranscriptionController extends Controller
{
    public function showTranscribeForm()
    {
        $transcriptions = Transcription::all();
        $results = session('results', []);
        $response = session('response', '');
        return view('transcribe', compact('transcriptions', 'results', 'response'));
    }

    public function transcribe(Request $request)
    {
        $request->validate([
            'video_url' => 'url',
        ]);

        $videoUrl = $request->input('video_url');

        $filename = Str::slug(parse_url($videoUrl, PHP_URL_PATH)) . '_' . time() . '.mp3';
        $outputFile = storage_path("app/public/audio/$filename");

        $command = "yt-dlp -x --audio-format mp3 -o \"$outputFile\" \"$videoUrl\"";
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            return redirect()->back()->withErrors(['error' => 'Failed to download audio: ' . implode("\n", $output)]);
        }

        try {
            // Send the audio file to AssemblyAI and start transcription
            $assemblyResponse = $this->sendToAssemblyAI($outputFile);
            $uploadUrl = $assemblyResponse['upload_url']; // Get the upload URL
            $transcriptionResponse = $this->startTranscription($uploadUrl); // Start transcription

            // Store transcription details in the database
            $this->storeTranscription($request->video_url, $transcriptionResponse['id']);
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        // Clean up the audio file after processing
        @unlink($outputFile);

        return redirect()->back()->with('message', 'Video transcription started successfully.');
    }

    protected function sendToAssemblyAI($filePath)
    {
        // Check if the file exists before trying to upload
        if (!file_exists($filePath)) {
            throw new \Exception("File does not exist: $filePath");
        }

        // Read the file content
        $fileContent = file_get_contents($filePath);

        // Prepare and make the API request to AssemblyAI
        $response = Http::withOptions(['verify' => false])
            ->withHeaders([
                'Authorization' => env('ASSEMBLYAI_API_KEY'),
                'Transfer-Encoding' => 'chunked',
            ])
            ->withBody($fileContent, 'application/octet-stream')
            ->post('https://api.assemblyai.com/v2/upload');

        // Check if the response is successful
        if (!$response->successful()) {
            Log::error('Failed to send audio to AssemblyAI: ', [
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new \Exception('Failed to send audio to AssemblyAI: ' . $response->body());
        }

        return $response->json(); // This will contain the upload URL and other metadata
    }

    protected function startTranscription($uploadUrl)
    {
        // Prepare the request body with the correct parameters
        $requestBody = [
            'audio_url' => $uploadUrl,
            'punctuate' => true,
            'word_boost' => [], // Add any word boosting here if needed
        ];

        $response = Http::withOptions(['verify' => false])
            ->withHeaders([
                'Authorization' => env('ASSEMBLYAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.assemblyai.com/v2/transcript', $requestBody);

        if (!$response->successful()) {
            throw new \Exception('Failed to start transcription: ' . $response->body());
        }

        return $response->json();
    }

    protected function storeTranscription($videoUrl, $transcriptionId)
    {
        // Create a new transcription record
        $transcription = new Transcription();
        $transcription->video_url = $videoUrl;
        $transcription->transcription_id = $transcriptionId;
        $transcription->status = 'processing'; // Set initial status
        $transcription->text = json_encode(['transcription' => '']); // Initialize with empty JSON
        $transcription->save();
    }

    protected function checkTranscriptionStatus($transcriptionId)
    {
        $response = Http::withOptions(['verify' => false])
            ->withHeaders([
                'Authorization' => env('ASSEMBLYAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])
            ->get("https://api.assemblyai.com/v2/transcript/{$transcriptionId}");

        if (!$response->successful()) {
            throw new \Exception('Failed to check transcription status: ' . $response->body());
        }

        return $response->json();
    }

    public function updateTranscription($transcriptionId)
    {
        // Find the transcription by its ID
        $transcription = Transcription::where('transcription_id', $transcriptionId)->first();

        if ($transcription) {
            // Check the transcription status from an external service
            $statusResponse = $this->checkTranscriptionStatus($transcriptionId);
            $transcription->status = $statusResponse['status'];

            // Check if transcription is completed
            if ($statusResponse['status'] === 'completed') {
                // Ensure the 'words' key exists and is an array
                if (isset($statusResponse['words']) && is_array($statusResponse['words'])) {
                    // Save transcription text and timestamps as JSON
                    $transcription->text = json_encode([
                        'transcription' => $statusResponse['words'],
                    ]);
                    $transcription->status = 'completed'; // Update status
                }
            }

            // Save the transcription
            $transcription->save();

            // Generate a title for the transcription
            $titlePrompt = "Create a short title for the following video transcription:";
            $transcriptionText = implode(' ', array_column($statusResponse['words'], 'text'));

            // Send the title prompt to ChatGPT
            $finalTitlePrompt = $titlePrompt . " " . $transcriptionText;

            try {
                // Get the title response from ChatGPT
                $gptTitleResponse = $this->getGPTResponse($finalTitlePrompt);

                // Assuming the response is a single string
                $transcription->title = $gptTitleResponse;
                $transcription->save(); // Save the title in the database

                // Send the first prompt to extract key points from ChatGPT
                $keypointsPrompt = "Extract 5 key points with timestamps from the following video transcription. 
            The first key point is 'intro' and the last is 'conclusion'. LIMIT THE RESPONSE to be one word!!!";

                // Append the transcription text to the prompt
                $finalKeypointsPrompt = $keypointsPrompt . " " . $transcriptionText;

                // Send the prompt to ChatGPT to get key points
                $gptKeypointsResponse = $this->getGPTResponse($finalKeypointsPrompt);

                // Save the GPT key points response in the 'keypoints' column
                $transcription->keypoints = json_encode($gptKeypointsResponse);
                $transcription->save();

                // Send the second prompt to summarize the transcription
                $summaryPrompt = "Summarize the following video transcription in a maximum of 100 words:";
                $finalSummaryPrompt = $summaryPrompt . " " . $transcriptionText;

                // Send the second prompt to ChatGPT for summarization
                $gptSummaryResponse = $this->getGPTResponse($finalSummaryPrompt);

                // Save the GPT summary response in the 'summary' column
                $transcription->summary = json_encode($gptSummaryResponse);
                $transcription->save();
            } catch (\Exception $e) {
                // Handle the exception if ChatGPT API fails
                return response()->json(['error' => 'Failed to process transcription: ' . $e->getMessage()], 500);
            }
        }
    }

    public function searchTimestamps(Request $request)
    {
        $request->validate([
            'search_word' => 'string|max:255',
        ]);

        $searchWord = strtolower($request->input('search_word')); // Make the search case-insensitive
        $transcriptions = Transcription::where('status', 'completed')->get();
        $results = [];

        foreach ($transcriptions as $transcription) {
            $transcriptionData = json_decode($transcription->text, true);

            if (isset($transcriptionData['transcription'])) {
                foreach ($transcriptionData['transcription'] as $wordData) {
                    // Check each word for a match (case-insensitive)
                    if (strtolower($wordData['text']) === $searchWord) {
                        // Convert milliseconds to minutes and seconds
                        $startTimeInSeconds = $wordData['start'] / 1000;
                        $minutes = floor($startTimeInSeconds / 60);
                        $seconds = $startTimeInSeconds % 60;

                        $results[] = [
                            'text' => $wordData['text'],
                            'start_time' => sprintf('%02d:%02d', $minutes, $seconds),
                            'confidence' => $wordData['confidence'],
                        ];
                    }
                }
            }
        }

        return redirect()->route('transcribe')->with('results', $results);
    }

    // NEW FUNCTIONALITY: Handle prompt parsing for ChatGPT
    public function parsePrompt(Request $request)
    {
        $request->validate([
            'prompt' => 'string|max:500',
            'transcription_ids' => 'array',
            'transcription_ids.*' => 'exists:transcriptions,transcription_id', // Ensure they exist
        ]);

        $prompt = $request->input('prompt');
        $transcriptionIds = request()->input('transcriptions', []); // This will get the input or default to an empty array

        if (in_array('all', $transcriptionIds)) {
            // Get all completed transcriptions
            $transcriptions = Transcription::where('status', 'completed')->get();
        } else {
            // Ensure we only attempt to query with an array
            $transcriptions = Transcription::whereIn('transcription_id', $transcriptionIds)->get();
        }
        if ($transcriptions->isEmpty()) {
            return redirect()->back()->withErrors(['error' => 'No selected transcriptions found.']);
        }
        // Process each transcription text through getTranscriptionText()
        $transcriptionTexts = $transcriptions->map(function ($transcription) {
            return $this->getTranscriptionText($transcription);
        });

        $combinedPrompt = "Transcription text: " . $transcriptionTexts->implode(' ') . "\nUser prompt: {$prompt}";


        // Send the combined prompt to ChatGPT (using the OpenAI API)
        try {
            $response = $this->getGPTResponse($combinedPrompt);
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to get response from ChatGPT: ' . $e->getMessage()]);
        }

        // Return the response to the view
        return redirect()->route('transcribe')->with('response', $response);
    }

    protected function getTranscriptionText($transcriptionJson)
    {
        $transcriptionData = json_decode($transcriptionJson->text, true);

        if (isset($transcriptionData['transcription'])) {
            $words = $transcriptionData['transcription'];
            $transcriptionText = '';

            foreach ($words as $wordData) {
                $startMilliseconds = $wordData['start'];
                // Convert milliseconds to hours, minutes, and seconds
                $totalSeconds = $startMilliseconds / 1000;
                $hours = floor($totalSeconds / 3600);
                $minutes = floor(($totalSeconds % 3600) / 60);
                $seconds = $totalSeconds % 60; // Keep fractional part

                // Build time string with only relevant larger units
                $timeStringParts = [];
                if ($hours > 0) {
                    $timeStringParts[] = $hours . ' hour' . ($hours != 1 ? 's' : '');
                }
                if ($minutes > 0) {
                    $timeStringParts[] = $minutes . ' minute' . ($minutes != 1 ? 's' : '');
                }
                if ($seconds > 0 || empty($timeStringParts)) {
                    // Format seconds to remove trailing zeros
                    $secondsFormatted = rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.');
                    $timeStringParts[] = $secondsFormatted . ' second' . ($secondsFormatted != '1' ? 's' : '');
                }

                $timeString = implode(' ', $timeStringParts);

                // Append to transcription text with word content
                $transcriptionText .= "[$timeString] {$wordData['text']} ";
            }

            return $transcriptionText;
        }

        return '';
    }

    protected function getGPTResponse($prompt)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            'Content-Type' => 'application/json',
        ])->withOptions([
            'verify' => false, // Disable SSL verification
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a specialized chatbot designed to interact solely based on a specific transcription provided to you. Your responses should exclusively reference and utilize the content of this transcription. Avoid incorporating external knowledge, personal opinions, or any additional context beyond what is contained in the transcription.

                    When you encounter timestamps in milliseconds, display only the start time and convert the milliseconds into seconds (format: 'X.XX seconds'). Do not include end times or any milliseconds in your response"
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ],
            ],
        ]);

        if (!$response->successful()) {
            Log::error('ChatGPT API error: ', [
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new \Exception('ChatGPT API request failed: ' . $response->body());
        }

        return $response->json()['choices'][0]['message']['content'];
    }
    public function summarizeTranscription(Request $request)
    {
        $request->validate([
            'transcription_ids' => 'array|required',
            'transcription_ids.*' => 'exists:transcriptions,transcription_id',
        ]);

        $transcriptionIds = $request->input('transcription_ids');

        if (in_array('all', $transcriptionIds)) {
            $transcriptions = Transcription::where('status', 'completed')->get();
        } else {
            $transcriptions = Transcription::whereIn('transcription_id', $transcriptionIds)->get();
        }

        if ($transcriptions->isEmpty()) {
            return redirect()->back()->withErrors(['error' => 'No selected transcriptions found.']);
        }

        $transcriptionTexts = $transcriptions->map(function ($transcription) {
            return $this->getTranscriptionText($transcription);
        });

        $summaryPrompt = "Please summarize the following text:" . $transcriptionTexts->implode(' ');

        try {
            $response = $this->getGPTResponse($summaryPrompt);
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to get response from ChatGPT: ' . $e->getMessage()]);
        }

        return redirect()->route('transcribe')->with('response', $response);
    }
}
