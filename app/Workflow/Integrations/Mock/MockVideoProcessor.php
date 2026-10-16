<?php

namespace App\Workflow\Integrations\Mock;

use App\Workflow\Integrations\Contracts\VideoProcessor;
use Illuminate\Support\Facades\Storage;

/**
 * Deterministic stand-in for media processing. File size / mime type come from
 * the real local file when it exists; everything else is sample data.
 *
 * TODO: Integrate real video processing (FFprobe/FFmpeg workers or AWS MediaConvert /
 * Mux). Metadata: run ffprobe on the stored object and persist codec, bitrate, duration,
 * resolution, audio tracks. Frames: extract keyframes and send them to a vision model
 * (e.g. Google Video Intelligence, AWS Rekognition) for object/scene labels. Thumbnails and
 * trailers: submit render jobs to a video-rendering service and poll/receive a webhook on
 * completion. Subtitles: generate WebVTT from the transcript and store per language.
 * All calls need timeouts, retries with backoff and idempotent job IDs.
 */
class MockVideoProcessor implements VideoProcessor
{
    use Deterministic;

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    public function metadata(array $asset): array
    {
        $seed = $this->seed($asset['id'] ?? null, $asset['file_path'] ?? null);
        $path = (string) ($asset['file_path'] ?? '');
        $disk = Storage::disk(config('workflows.uploads.disk', 'local'));
        $exists = $path !== '' && $disk->exists($path);

        return $this->simulatedMeta('video metadata extraction') + [
            'asset_id' => $asset['id'] ?? null,
            'file_found' => $exists,
            'size_bytes' => $exists ? $disk->size($path) : ($asset['size_bytes'] ?? null),
            'mime_type' => $exists ? $disk->mimeType($path) : ($asset['mime_type'] ?? 'video/mp4'),
            'duration_seconds' => $asset['duration_seconds'] ?? (1200 + $seed % 4200),
            'resolution' => $this->pick(['1920x1080', '3840x2160', '1280x720'], $seed),
            'frame_rate' => $this->pick([23.976, 24, 25, 29.97], $seed, 1),
            'video_codec' => $this->pick(['h264', 'hevc', 'vp9'], $seed, 2),
            'audio_tracks' => [['language' => $asset['language'] ?? 'en', 'codec' => 'aac', 'channels' => 2]],
            'bitrate_kbps' => 4000 + $seed % 8000,
        ];
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    public function analyzeFrames(array $asset, int $frameCount): array
    {
        $seed = $this->seed($asset['id'] ?? null, 'frames');
        $labels = ['person', 'city skyline', 'car', 'forest', 'ocean', 'kitchen', 'crowd', 'night sky', 'laptop', 'dog', 'stage lights', 'mountain road'];
        $frames = [];

        for ($i = 0; $i < $frameCount; $i++) {
            $frames[] = [
                'timestamp' => gmdate('H:i:s', (int) (($i + 1) * (($asset['duration_seconds'] ?? 2400) / ($frameCount + 1)))),
                'labels' => $this->pickMany($labels, $seed + $i, 3),
                'brightness' => round(0.3 + (($seed + $i * 13) % 60) / 100, 2),
            ];
        }

        $objects = array_values(array_unique(array_merge(...array_column($frames, 'labels'))));

        return $this->simulatedMeta('frame analysis') + ['frames' => $frames, 'objects' => array_slice($objects, 0, 6)];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function thumbnail(array $content, array $options): array
    {
        $seed = $this->seed($content['id'] ?? null, $options);

        return $this->simulatedMeta('thumbnail generation') + [
            'content_id' => $content['id'] ?? null,
            'file_generated' => false,
            'image_url' => null,
            'candidate_frames' => [
                ['timestamp' => gmdate('H:i:s', 300 + $seed % 900), 'score' => 0.91],
                ['timestamp' => gmdate('H:i:s', 1300 + $seed % 900), 'score' => 0.87],
            ],
            'aspect_ratio' => $options['aspect_ratio'] ?? '16:9',
            'resolution' => $options['resolution'] ?? '1920x1080',
            'prompt' => $options['prompt'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function storyboard(array $content, array $options): array
    {
        $seed = $this->seed($content['id'] ?? null, $options);
        $duration = (int) ($options['target_duration'] ?? 60);
        $title = (string) ($content['title'] ?? 'Untitled');
        $beats = ['Cold open — establish the world', 'Introduce the protagonist', 'Inciting incident', 'Rising tension montage', 'Climactic glimpse', 'Title card & release date'];
        $sceneCount = $duration <= 30 ? 4 : 6;
        $per = (int) floor($duration / $sceneCount);
        $scenes = [];

        for ($i = 0; $i < $sceneCount; $i++) {
            $scenes[] = [
                'scene' => $i + 1,
                'start' => $i * $per,
                'duration' => $per,
                'description' => $beats[$i],
                'text_overlay' => match ($i) {
                    0 => null,
                    $sceneCount - 1 => strtoupper($title),
                    default => $this->pick(['This season', 'One choice', 'Everything changes', 'No way back'], $seed, $i),
                },
                'music_cue' => $this->pick(['ambient swell', 'percussive build', 'silence', 'main theme'], $seed, $i),
            ];
        }

        return $this->simulatedMeta('trailer storyboard') + [
            'title' => $title,
            'target_duration_seconds' => $duration,
            'language' => $options['language'] ?? 'en',
            'aspect_ratio' => $options['aspect_ratio'] ?? '16:9',
            'scenes' => $scenes,
            'video_rendered' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $asset
     * @param  list<string>  $targetLanguages
     * @return array<string, mixed>
     */
    public function subtitles(array $asset, string $sourceLanguage, array $targetLanguages): array
    {
        $seed = $this->seed($asset['id'] ?? null, 'subs');
        $tracks = [];

        foreach (array_merge([$sourceLanguage], $targetLanguages) as $i => $language) {
            $tracks[] = [
                'language' => $language,
                'kind' => $i === 0 ? 'captions' : 'subtitles',
                'task_type' => $i === 0 ? 'subtitle' : 'translation',
                'format' => 'webvtt',
                'cue_count' => 380 + ($seed + (int) $i) % 220,
                'status' => $i === 0 ? 'generated_simulated' : 'translation_pending',
                'file_generated' => false,
            ];
        }

        return $this->simulatedMeta('subtitle generation') + ['source_language' => $sourceLanguage, 'tracks' => $tracks];
    }
}
