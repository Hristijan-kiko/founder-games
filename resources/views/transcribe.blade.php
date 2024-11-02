<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transcribe Video</title>
</head>

<body>
    <div class="container mx-auto p-4">
        <h1 class="text-2xl font-bold mb-4">Transcribe Video</h1>

        @if (session('message'))
            <div class="bg-green-200 text-green-800 p-2 mb-4 rounded">
                {{ session('message') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="bg-red-200 text-red-800 p-2 mb-4 rounded">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Video URL Transcription Form -->
        <form action="{{ route('transcribe') }}" method="POST">
            @csrf
            <div class="mb-4">
                <label for="video_url" class="block text-sm font-medium">Video URL:</label>
                <input type="url" name="video_url" id="video_url" required
                    class="mt-1 p-2 border border-gray-300 rounded w-full" placeholder="Enter the video URL">
            </div>
            <button type="submit" class="bg-blue-500 text-white p-2 rounded">Start Transcription</button>
        </form>

        <hr class="my-4">

        <!-- Word Search Form -->
        <form action="{{ route('search.timestamps') }}" method="POST">
            @csrf
            <div class="mb-4">
                <label for="search_word" class="block text-sm font-medium">Search Word:</label>
                <input type="text" name="search_word" id="search_word" required
                    class="mt-1 p-2 border border-gray-300 rounded w-full" placeholder="Enter the word to search for">
            </div>
            <button type="submit" class="bg-green-500 text-white p-2 rounded">Search Word in Transcription</button>
        </form>
        <br>
        <br>
        <br>

        <!-- Results Section -->
        @if (isset($results) && count($results) > 0)
            <h2 class="text-xl font-semibold">Search Results:</h2>
            <ul class="list-disc pl-5">
                @foreach ($results as $result)
                    <li>
                        Word: "{{ $result['text'] }}" - Time: {{ $result['start_time'] }} - Confidence:
                        {{ $result['confidence'] }}
                    </li>
                @endforeach
            </ul>
        @elseif (isset($results))
            <p>No results found for the word.</p>
        @endif
    </div>
</body>

</html>
