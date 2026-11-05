<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BriefState;
use App\Enums\ShotState;
use App\Jobs\MuxFinalVideoJob;
use App\Models\Brief;
use App\Models\Shot;
use App\Services\Providers\ReplayAudioProvider;
use App\Services\Providers\ReplayVideoProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MuxFinalVideoJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_ffmpeg_muxes_passing_clips_and_audio_into_one_mp4(): void
    {
        Storage::fake('s3');

        $clip = $this->replayClip();
        $audio = (new ReplayAudioProvider)->generate('15s product teaser, rainy Tokyo, neon');

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Muxing,
            'audio_path' => 'audio/brief_voiceover.wav',
            'metadata' => [
                'sound_effect_path' => 'audio/brief_sfx.wav',
            ],
        ]);

        Storage::disk('s3')->put($brief->audio_path, $audio->voiceover);
        Storage::disk('s3')->put($brief->metadata['sound_effect_path'], $audio->soundEffect);

        $this->createShot($brief, 1, ShotState::Done, 'videos/shot_1.mp4', $clip);
        $this->createShot($brief, 2, ShotState::Failed, 'videos/shot_2.mp4', $clip);
        $this->createShot($brief, 3, ShotState::Done, 'videos/shot_3.mp4', $clip);

        (new MuxFinalVideoJob($brief->id))->handle();

        $brief->refresh();
        $this->assertSame(BriefState::Done, $brief->state);
        $this->assertNotNull($brief->final_video_path);

        $mp4 = Storage::disk('s3')->get($brief->final_video_path);
        $this->assertIsString($mp4);
        $this->assertStringContainsString('ftyp', $mp4);
        $this->assertEqualsWithDelta(2.0, $this->durationSeconds($mp4), 0.05);
    }

    public function test_final_video_length_follows_the_clips_when_audio_is_longer(): void
    {
        Storage::fake('s3');

        $clip = $this->replayClip();

        $brief = Brief::create([
            'prompt' => '15s product teaser, rainy Tokyo, neon',
            'state' => BriefState::Muxing,
            'audio_path' => 'audio/brief_voiceover.wav',
            'metadata' => [
                'sound_effect_path' => 'audio/brief_sfx.wav',
            ],
        ]);

        Storage::disk('s3')->put($brief->audio_path, $this->silentWav(5));
        Storage::disk('s3')->put($brief->metadata['sound_effect_path'], $this->silentWav(5));

        $this->createShot($brief, 1, ShotState::Done, 'videos/shot_1.mp4', $clip);
        $this->createShot($brief, 2, ShotState::Done, 'videos/shot_2.mp4', $clip);

        (new MuxFinalVideoJob($brief->id))->handle();

        $mp4 = Storage::disk('s3')->get($brief->refresh()->final_video_path);
        $this->assertEqualsWithDelta(2.0, $this->durationSeconds($mp4), 0.1);
    }

    private function silentWav(int $seconds): string
    {
        $sampleRate = 8000;
        $data = str_repeat("\0\0", $sampleRate * $seconds);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
          .'fmt '.pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
          .'data'.pack('V', strlen($data)).$data;
    }

    private function replayClip(): string
    {
        $provider = new ReplayVideoProvider(pendingPolls: 0);
        $renderId = $provider->submit('A neon alley', 'keyframes/shot.png');
        $result = $provider->poll($renderId);

        return (string) $result->videoContent;
    }

    private function createShot(
        Brief $brief,
        int $sequence,
        ShotState $state,
        string $videoPath,
        string $clip,
    ): void {
        Storage::disk('s3')->put($videoPath, $clip);

        Shot::create([
            'brief_id' => $brief->id,
            'sequence' => $sequence,
            'prompt' => "Shot {$sequence}",
            'state' => $state,
            'video_path' => $videoPath,
        ]);
    }

    private function durationSeconds(string $mp4): float
    {
        $path = tempnam(sys_get_temp_dir(), 'mux_').'.mp4';
        file_put_contents($path, $mp4);

        $probe = Process::run([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=nw=1:nk=1',
            $path,
        ]);

        if (file_exists($path)) {
            unlink($path);
        }

        return (float) trim($probe->output());
    }
}
