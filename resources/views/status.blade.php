<!-- resources/views/status.blade.php -->
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transcription Status</title>
</head>
<h1>Transcription Status</h1>

@if ($transcriptions->isEmpty())
    <p>No transcriptions found.</p>
@else
    <table>
        <thead>
            <tr>
                <th>Video URL</th>
                <th>Transcription Status</th>
                <th>Transcription Text</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transcriptions as $transcription)
                <tr>
                    <td>{{ $transcription->video_url }}</td>
                    <td>{{ ucfirst($transcription->status) }}</td>
                    <td>
                        @if ($transcription->status === 'completed')
                            <pre>{{ $transcription->text }}</pre>
                        @else
                            N/A
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
<script>
    document.getElementById('refreshStatus').addEventListener('click', function() {
        const messageDiv = document.getElementById('message');
        messageDiv.innerHTML = ''; // Clear previous messages
        messageDiv.className = ''; // Clear any previous classes
        messageDiv.innerHTML = '<p>Refreshing transcription statuses...</p>';
        messageDiv.classList.add('alert', 'alert-info');

        // Fetch updated statuses from the server
        fetch('/transcriptions/check-status')
            .then(response => response.json())
            .then(data => {
                if (data.message) {
                    messageDiv.innerHTML = `<p>${data.message}</p>`;
                    messageDiv.classList.add('alert', 'alert-success');
                }

                // Update the transcription table with new data
                const transcriptionTable = document.getElementById('transcriptionTable');
                transcriptionTable.innerHTML = ''; // Clear existing rows

                data.transcriptions.forEach(transcription => {
                    const row = `
                        <tr>
                            <td>${transcription.video_url}</td>
                            <td>${transcription.transcription_id}</td>
                            <td>${transcription.status.charAt(0).toUpperCase() + transcription.status.slice(1)}</td>
                            <td>${transcription.text || 'Pending...'}</td>
                        </tr>`;
                    transcriptionTable.innerHTML += row;
                });
            })
            .catch(error => {
                messageDiv.innerHTML = `<p>Something went wrong. Please try again later.</p>`;
                messageDiv.classList.add('alert', 'alert-danger');
            });
    });
</script>
</body>

</html>
