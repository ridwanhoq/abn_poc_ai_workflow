<?php

namespace App\Workflow\Integrations\Mock;

use App\Workflow\Integrations\Contracts\Transcriber;

/**
 * Deterministic sample transcript. No audio is processed.
 *
 * TODO: Integrate a production speech-to-text provider (e.g. AWS Transcribe, Google
 * Speech-to-Text, Deepgram, Whisper) and persist the generated transcript. Credentials:
 * provider API key / IAM role. Request: audio track URI from object storage + language hint.
 * Response: timed segments with confidence scores. Long files must use async jobs with
 * webhook/polling, a timeout, and retries on transient errors.
 */
class MockTranscriber implements Transcriber
{
    use Deterministic;

    private const LINES = [
        'I never thought we would make it this far.',
        'The city looks different at night, doesn\'t it?',
        'We have one chance to get this right.',
        'Tell me what really happened that summer.',
        'Everyone has a secret. Mine just happens to be bigger.',
        'Keep moving — they are right behind us.',
        'This is where it all started.',
        'Promise me you\'ll come back.',
    ];

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    public function transcribe(array $asset, string $language): array
    {
        $seed = $this->seed($asset['id'] ?? null, $language);
        $segments = [];

        foreach ($this->pickMany(self::LINES, $seed, 5) as $i => $line) {
            $segments[] = [
                'start' => gmdate('H:i:s', 60 + $i * 245 + $seed % 40),
                'speaker' => 'SPEAKER_'.(($seed + $i) % 2 + 1),
                'text' => $line,
                'confidence' => round(0.86 + (($seed + $i) % 12) / 100, 2),
            ];
        }

        return $this->simulatedMeta('transcription') + [
            'language' => $language,
            'segments' => $segments,
            'text' => implode(' ', array_column($segments, 'text')),
            'word_count' => str_word_count(implode(' ', array_column($segments, 'text'))),
        ];
    }
}
