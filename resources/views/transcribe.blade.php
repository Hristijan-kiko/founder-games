<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transcribe Video</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>

<body>
    <div class="container mx-auto p-4">
        <h1 class="text-2xl font-bold mb-4">Transcribe Video</h1>

        <!-- Flash messages for success or error -->
        @if (session('message'))
            <div class="bg-success text-white p-2 mb-4 rounded">
                {{ session('message') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="bg-danger text-white p-2 mb-4 rounded">
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
                <label for="video_url" class="form-label">Video URL:</label>
                <input type="url" name="video_url" id="video_url" required class="form-control"
                    placeholder="Enter the video URL">
            </div>
            <button type="submit" class="btn btn-primary">Start Transcription</button>
        </form>

        <hr class="my-4">

        <!-- Word Search Form -->
        <form action="{{ route('search.timestamps') }}" method="POST">
            @csrf
            <div class="mb-4">
                <label for="search_word" class="form-label">Search Word:</label>
                <input type="text" name="search_word" id="search_word" required class="form-control"
                    placeholder="Enter the word to search for">
            </div>
            <button type="submit" class="btn btn-primary">Search Word in Transcription</button>
        </form>

        <hr class="my-4">

        <!-- ChatGPT Prompt Form -->
        <form action="{{ route('parse.prompt') }}" method="POST">
            @csrf
            <div class="mb-4">
                <label for="prompt" class="form-label">Ask ChatGPT:</label>
                <textarea name="prompt" id="prompt" class="form-control" required cols="30" rows="2"
                    placeholder="Enter your question"></textarea>
            </div>

            <div class="mb-4">
                <label for="transcriptions" class="form-label">Select Transcriptions:</label>
                <select name="transcriptions[]" id="transcriptions" class="form-select" required>
                    <option value="all">All</option>
                    @foreach ($transcriptions as $transcription)
                        <option value="{{ $transcription->transcription_id }}">{{ $transcription->video_url }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Submit Prompt</button>
        </form>

        <hr class="my-4">

        <!-- Results Section -->
        @if (session('results') && count(session('results')) > 0)
            <h2 class="text-xl font-semibold">Search Results:</h2>
            <ul class="list-disc pl-5">
                @foreach (session('results') as $result)
                    <li>
                        Word: "{{ $result['text'] }}" - Time: {{ $result['start_time'] }}
                    </li>
                @endforeach
            </ul>
        @elseif (session('results'))
            <p>No results found for the word.</p>
        @endif

        <!-- ChatGPT Response Section -->
        @if (session('response'))
            <hr class="my-4">
            <h2 class="text-xl font-semibold">ChatGPT Response:</h2>
            <p class="bg-light p-3 rounded">{{ session('response') }}</p>
        @endif

    </div>

    <script>
        document.getElementById('transcriptions').addEventListener('change', function() {
            const options = this.options;
            const allSelected = Array.from(options).some(option => option.value === 'all' && option.selected);

            for (let option of options) {
                if (option.value !== 'all') {
                    option.disabled = allSelected && option.selected;
                }
            }
        });
    </script>
</body>

</html>
