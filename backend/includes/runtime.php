<?php
/** Bounded subprocess execution and the real local conversion engine. */
namespace MSVC;

final class Process {
    private static $active = null;
    private static int $pid = 0;
    public static function stop(): void {
        if (self::$pid > 0 && function_exists('posix_kill')) {
            @posix_kill(-self::$pid, 15);
            usleep(100000);
            @posix_kill(-self::$pid, 9);
        }
        if (is_resource(self::$active)) { @proc_terminate(self::$active, 9); }
    }
    public static function run(array $argv, int $seconds, string $setsid, ?callable $line = null, ?callable $tick = null, ?string $cwd = null): array {
        if (!function_exists('proc_open') || !function_exists('posix_kill')) { throw new \RuntimeException('PHP process execution and the POSIX extension are required.'); }
        $command = array_merge([$setsid], $argv);
        $proc = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($proc)) { throw new \RuntimeException('Could not start the server process.'); }
        self::$active = $proc;
        $initial = proc_get_status($proc); self::$pid = (int)$initial['pid'];
        foreach ([1, 2] as $index) { stream_set_blocking($pipes[$index], false); }
        $started = microtime(true); $out = ''; $err = ''; $buffers = [1 => '', 2 => '']; $exit = -1; $finished = false;
        try {
            while (true) {
                foreach ([1, 2] as $index) {
                    $chunk = stream_get_contents($pipes[$index]);
                    if ($chunk === false || $chunk === '') { continue; }
                    if ($index === 1) {
                        $out .= $chunk;
                        if (strlen($out) > 6291456) { throw new \RuntimeException('The source returned too much data.'); }
                    } else { $err = substr($err . $chunk, -16384); }
                    $buffers[$index] .= $chunk;
                    if (strlen($buffers[$index]) > 6291456) { throw new \RuntimeException('The source returned too much data.'); }
                    while (($pos = strpos($buffers[$index], "\n")) !== false) {
                        $text = substr($buffers[$index], 0, $pos);
                        $buffers[$index] = substr($buffers[$index], $pos + 1);
                        if ($line) { $line(trim($text)); }
                    }
                }
                if ($tick) { $tick(); }
                $status = proc_get_status($proc);
                if (!$status['running']) { $exit = (int)$status['exitcode']; $finished = true; break; }
                if (microtime(true) - $started > $seconds) { throw new \RuntimeException('Processing timed out. Try a shorter video or lower quality.'); }
                usleep(100000);
            }
            foreach ([1, 2] as $index) {
                $tail = stream_get_contents($pipes[$index]) ?: '';
                if ($index === 1) { $out .= $tail; } else { $err = substr($err . $tail, -16384); }
                if ($line && ($buffers[$index] . $tail) !== '') { $line(trim($buffers[$index] . $tail)); }
            }
        } finally {
            if (!$finished) { self::stop(); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            $closed = proc_close($proc);
            if ($exit < 0) { $exit = $closed; }
            self::$active = null; self::$pid = 0;
        }
        return ['code' => $exit, 'stdout' => $out, 'stderr' => $err];
    }
}

final class Engine {
    private array $config;
    private Store $store;
    private array $job;
    private float $lastWrite = 0;
    private float $deadline;
    public function __construct(Store $store, array $job) {
        $this->store = $store; $this->job = $job; $this->config = $job['config'];
        $this->deadline = microtime(true) + (int)$this->config['timeout'];
    }
    private function remaining(): int { return max(1, (int)ceil($this->deadline - microtime(true))); }
    private function update(string $phase, ?int $progress = null, bool $force = false): void {
        $this->job['phase'] = $phase;
        if ($progress !== null) { $this->job['progress'] = min(99, max((int)($this->job['progress'] ?? 0), $progress)); }
        if ($force || microtime(true) - $this->lastWrite >= 1) {
            $this->store->write($this->job); $this->lastWrite = microtime(true);
        }
    }
    private function tick(): void {
        if (is_file($this->store->dir($this->job['id']) . '/cancel.flag')) { throw new \RuntimeException('This request was cancelled.'); }
        if (microtime(true) > $this->deadline) { throw new \RuntimeException('Processing timed out. Try a shorter video or lower quality.'); }
        $bytes = 0;
        $media = $this->store->dir($this->job['id']) . '/media';
        foreach (glob($media . '/*') ?: [] as $path) {
            if (is_link($path)) { throw new \RuntimeException('Invalid output file.'); }
            if (is_file($path)) { clearstatcache(true, $path); $bytes += filesize($path); }
        }
        if ($bytes > (int)$this->config['max_mb'] * 1048576 * 3) { throw new \RuntimeException('This video needs too much temporary storage. Choose a lower quality.'); }
        $free = @disk_free_space($this->store->root);
        if ($free !== false && $free < 134217728) { throw new \RuntimeException('The server is low on temporary storage. Try again later.'); }
        $this->update($this->job['phase']);
    }
    private function base(): array {
        return [$this->config['yt_dlp'], '--ignore-config', '--no-plugin-dirs', '--no-cache-dir', '--no-playlist', '--socket-timeout', '15', '--retries', '1', '--extractor-retries', '1', '--use-extractors', 'Youtube', '--no-js-runtimes', '--js-runtimes', $this->config['js_type'] . ':' . $this->config['js_path']];
    }
    private function call(array $argv, ?callable $line = null, int $limit = 0): array {
        $result = Process::run($argv, $limit ? min($this->remaining(), $limit) : $this->remaining(), $this->config['setsid'], $line, fn() => $this->tick(), $this->store->dir($this->job['id']));
        if ($result['code'] !== 0) {
            $log = $this->store->dir($this->job['id']) . '/error.log';
            file_put_contents($log, substr($result['stderr'], -16384)); @chmod($log, 0600);
            throw new \RuntimeException('The source could not be processed. Try an available public video you have permission to download.');
        }
        return $result;
    }
    public static function metadata(array $raw, array $config, string $expectedId): array {
        if (($raw['id'] ?? '') !== $expectedId || ($raw['_type'] ?? 'video') !== 'video') { throw new \RuntimeException('Only individual YouTube videos are supported.'); }
        if (!empty($raw['is_live']) || in_array($raw['live_status'] ?? '', ['is_live', 'is_upcoming'], true)) { throw new \RuntimeException('Live streams and upcoming videos are not supported.'); }
        $duration = (float)($raw['duration'] ?? 0);
        if (!is_finite($duration) || $duration <= 0 || $duration > $config['max_minutes'] * 60) {
            throw new \RuntimeException('Choose a video no longer than ' . $config['max_minutes'] . ' minutes.');
        }
        $qualities = []; $audio = false;
        foreach ($raw['formats'] ?? [] as $format) {
            if (!empty($format['has_drm'])) { continue; }
            $height = (int)($format['height'] ?? 0);
            if (($format['vcodec'] ?? 'none') !== 'none' && $height > 0 && $height <= $config['max_height']) { $qualities[$height] = $height; }
            if (($format['acodec'] ?? 'none') !== 'none') { $audio = true; }
        }
        rsort($qualities, SORT_NUMERIC);
        if (!$qualities && !$audio) { throw new \RuntimeException('No supported audio or video formats were found.'); }
        return ['video_id' => $expectedId, 'title' => substr((string)($raw['title'] ?? 'YouTube video'), 0, 1000), 'duration' => $duration, 'thumbnail' => 'https://i.ytimg.com/vi/' . $expectedId . '/hqdefault.jpg', 'qualities' => array_values($qualities), 'has_audio' => $audio];
    }
    public function analyze(): array {
        $video = Input::video($this->job['url']);
        $this->update('Checking video', null, true);
        $args = array_merge($this->base(), ['--dump-single-json', '--skip-download', '--', $video['url']]);
        $result = $this->call($args, null, 60);
        $raw = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
        return self::metadata($raw, $this->config, $video['id']);
    }
    private function probe(string $path): array {
        $result = $this->call([$this->config['ffprobe'], '-v', 'error', '-show_entries', 'format=duration,format_name:stream=codec_type,codec_name,height', '-of', 'json', $path], null, 15);
        return json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
    }
    public function convert(): array {
        $video = Input::video($this->job['url']);
        $format = $this->job['format']; $quality = (int)$this->job['quality'];
        if (!in_array($format, ['mp3', 'mp4'], true)) { throw new \RuntimeException('Invalid output format.'); }
        $info = $this->job['info'];
        if (($format === 'mp4' && (!in_array($quality, $info['qualities'], true) || $quality > $this->config['max_height'])) || ($format === 'mp3' && (!in_array($quality, [128, 192, 320], true) || !$info['has_audio']))) {
            throw new \RuntimeException('This quality option is not available.');
        }
        $media = $this->store->dir($this->job['id']) . '/media';
        if (!is_dir($media)) { mkdir($media, 0700); }
        $selector = $format === 'mp3' ? 'bestaudio/best' : 'bestvideo[height<=' . $quality . ']+bestaudio/best[height<=' . $quality . ']';
        $this->update('Downloading source', 0, true);
        $args = array_merge($this->base(), ['--newline', '--progress', '--progress-template', 'download:MSVC_PROGRESS:%(progress._percent_str)s', '--max-filesize', (string)($this->config['max_mb'] * 1048576), '--match-filter', 'duration <= ' . ($this->config['max_minutes'] * 60) . ' & !is_live', '--ffmpeg-location', dirname($this->config['ffmpeg']), '--merge-output-format', 'mkv', '-f', $selector, '-o', $media . '/source.%(ext)s', '--', $video['url']]);
        $this->call($args, function(string $line): void {
            if (preg_match('/MSVC_PROGRESS:\s*([0-9.]+)%/', $line, $m)) { $this->update('Downloading source', (int)round(min(100, (float)$m[1]) * .60)); }
        });
        $sources = array_values(array_filter(glob($media . '/source.*') ?: [], fn($p) => preg_match('/^source\.(?:mp4|mkv|webm|m4a|mp3|ogg|opus|aac|flac|wav)$/D', basename($p)) && !is_link($p)));
        if (count($sources) !== 1) { throw new \RuntimeException('A complete source file was not produced.'); }
        $source = $sources[0]; $probe = $this->probe($source);
        $duration = (float)($probe['format']['duration'] ?? 0);
        if ($duration <= 0 || $duration > $this->config['max_minutes'] * 60 + 1) { throw new \RuntimeException('The downloaded video exceeds the duration limit.'); }
        $streams = array_column($probe['streams'] ?? [], 'codec_type');
        if (!in_array($format === 'mp3' ? 'audio' : 'video', $streams, true)) { throw new \RuntimeException('The requested media stream is unavailable.'); }
        $output = $media . '/download.' . $format;
        $args = [$this->config['ffmpeg'], '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-threads', '1', '-filter_threads', '1', '-i', $source, '-map_metadata', '-1'];
        if ($format === 'mp4') {
            $args = array_merge($args, ['-map', '0:v:0', '-map', '0:a:0?', '-vf', 'scale=-2:trunc(min(' . $quality . '\\,ih)/2)*2', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '23', '-pix_fmt', 'yuv420p', '-threads', '1', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart']);
        } else { $args = array_merge($args, ['-map', '0:a:0', '-vn', '-c:a', 'libmp3lame', '-b:a', $quality . 'k', '-threads', '1']); }
        $args = array_merge($args, ['-fs', (string)($this->config['max_mb'] * 1048576), '-progress', 'pipe:1', '-nostats', $output]);
        $this->update('Converting file', 65, true);
        $this->call($args, function(string $line) use ($duration): void {
            if (preg_match('/^out_time_us=(\d+)$/D', $line, $m)) { $this->update('Converting file', 65 + (int)round(min(1, (int)$m[1] / 1000000 / $duration) * 33)); }
        });
        clearstatcache(true, $output);
        if (!is_file($output) || is_link($output) || filesize($output) < 1 || filesize($output) > $this->config['max_mb'] * 1048576) { throw new \RuntimeException('The output exceeds the file size limit. Choose a lower quality.'); }
        $final = $this->probe($output);
        $finalDuration = (float)($final['format']['duration'] ?? 0);
        if ($finalDuration < $duration - max(.5, $duration * .01)) { throw new \RuntimeException('The complete file exceeds the size limit. Choose a lower quality.'); }
        @chmod($output, 0600);
        foreach (glob($media . '/*') ?: [] as $path) { if ($path !== $output) { Store::remove($path); } }
        return ['file' => 'media/download.' . $format, 'filename' => Input::filename($info['title'], $format), 'size' => filesize($output), 'mime' => $format === 'mp3' ? 'audio/mpeg' : 'video/mp4'];
    }
}
