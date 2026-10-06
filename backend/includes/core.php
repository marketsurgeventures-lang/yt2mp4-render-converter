<?php
/** Shared, WordPress-independent validation and private job storage. */
namespace MSVC;

final class Input {
    public static function video(string $value): array {
        $value = trim($value);
        if (strlen($value) > 2048) { throw new \RuntimeException('The video link is too long.'); }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/D', $value)) {
            $id = $value;
        } else {
            $u = parse_url($value);
            if (!$u || strtolower($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass']) || isset($u['port'])) {
                throw new \RuntimeException('Paste a valid HTTPS YouTube video link.');
            }
            $host = strtolower($u['host'] ?? '');
            $path = $u['path'] ?? '';
            if ($host === 'youtu.be') {
                $id = trim($path, '/');
            } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
                if ($path === '/watch') {
                    parse_str($u['query'] ?? '', $query);
                    $id = is_string($query['v'] ?? null) ? $query['v'] : '';
                } elseif (preg_match('~^/(?:shorts|embed|live)/([A-Za-z0-9_-]{11})/?$~D', $path, $m)) {
                    $id = $m[1];
                } else { $id = ''; }
            } else { throw new \RuntimeException('Only YouTube video links are supported.'); }
            if (!preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) {
                throw new \RuntimeException('This link does not contain a valid YouTube video ID.');
            }
        }
        return ['id' => $id, 'url' => 'https://www.youtube.com/watch?v=' . $id];
    }

    public static function filename(string $title, string $format): string {
        $title = preg_replace('/[^\pL\pN _-]+/u', '', $title) ?: 'video';
        $title = trim(preg_replace('/\s+/u', ' ', $title), ' ._-');
        // Keep HTTP headers ASCII; the displayed title retains Unicode.
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) : $title;
        $ascii = preg_replace('/[^A-Za-z0-9 _-]/', '', (string)$ascii);
        return (substr(trim($ascii), 0, 100) ?: 'video') . '.' . ($format === 'mp3' ? 'mp3' : 'mp4');
    }

    public static function range(?string $header, int $size): array {
        if (!$header) { return [0, $size - 1, false]; }
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', trim($header), $m) || ($m[1] === '' && $m[2] === '')) {
            throw new \RuntimeException('Invalid byte range.');
        }
        if ($m[1] === '') {
            $length = (int)$m[2];
            if ($length < 1) { throw new \RuntimeException('Invalid byte range.'); }
            $start = max(0, $size - $length); $end = $size - 1;
        } else {
            $start = (int)$m[1]; $end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
        }
        if ($start >= $size || $start < 0 || $end < $start) { throw new \RuntimeException('Invalid byte range.'); }
        return [$start, $end, true];
    }
}

final class Store {
    public string $root;
    public $observer = null;
    public function __construct(string $root, array $webRoots = []) {
        $root = rtrim($root, '/');
        if (!str_starts_with($root, '/') || strlen($root) < 9 || str_contains($root, "\0") || preg_match('~(?:^|/)\.\.?(/|$)~', $root) || in_array($root, ['/var/lib', '/var/www', '/usr/local', '/workspace', '/home'], true)) {
            throw new \RuntimeException('Choose a dedicated absolute private storage directory.');
        }
        if (is_link($root)) { throw new \RuntimeException('Private storage must not be a symlink.'); }
        // Validate the nearest existing ancestor before creating anything.
        $ancestor = $root;
        while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) { $ancestor = dirname($ancestor); }
        $resolved = rtrim((string)realpath($ancestor), '/') . substr($root, strlen($ancestor));
        foreach ($webRoots as $webRoot) {
            $web = rtrim((string)realpath($webRoot), '/');
            if ($web !== '' && ($resolved === $web || str_starts_with($resolved, $web . '/'))) {
                throw new \RuntimeException('Private storage must be outside the public website directory.');
            }
        }
        if (is_dir($root) && !is_file($root . '/.msvc-store')) {
            $contents = array_diff(scandir($root) ?: [], ['.', '..']);
            if ($contents) { throw new \RuntimeException('Use an empty dedicated directory for private storage.'); }
        }
        if (!is_dir($root) && !@mkdir($root, 0700, true)) { throw new \RuntimeException('Cannot create private storage. Check folder ownership.'); }
        $this->root = (string)realpath($root);
        if (!$this->root || !is_writable($this->root)) { throw new \RuntimeException('Private storage is not writable by PHP.'); }
        foreach ($webRoots as $webRoot) {
            $web = rtrim((string)realpath($webRoot), '/');
            if ($web && ($this->root === $web || str_starts_with($this->root, $web . '/'))) {
                throw new \RuntimeException('Private storage must be outside the public website directory.');
            }
        }
        @chmod($this->root, 0700);
        if (!is_file($this->root . '/.msvc-store')) { file_put_contents($this->root . '/.msvc-store', 'msvc-v1'); @chmod($this->root . '/.msvc-store', 0600); }
        foreach (['jobs', 'limits'] as $name) {
            $dir = $this->root . '/' . $name;
            if (is_link($dir)) { throw new \RuntimeException('Invalid storage layout.'); }
            if (!is_dir($dir) && !@mkdir($dir, 0700)) { throw new \RuntimeException('Cannot initialize private storage.'); }
        }
    }

    public function dir(string $id): string {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new \RuntimeException('Invalid job identifier.'); }
        $path = $this->root . '/jobs/' . $id;
        if (is_link($path)) { throw new \RuntimeException('Invalid job directory.'); }
        return $path;
    }
    public function read(string $id): array {
        $path = $this->dir($id) . '/job.json';
        if (!is_file($path) || is_link($path) || filesize($path) > 262144) { throw new \RuntimeException('This request has expired. Start again.'); }
        $data = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['id'] ?? '') !== $id) { throw new \RuntimeException('Invalid job data.'); }
        return $data;
    }
    public function write(array $job): void {
        $dir = $this->dir($job['id']);
        if (!is_dir($dir) && !mkdir($dir, 0700)) { throw new \RuntimeException('Cannot create a job directory.'); }
        $job['heartbeat'] = time();
        $tmp = $dir . '/job.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, json_encode($job, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot save conversion progress.');
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $dir . '/job.json')) { @unlink($tmp); throw new \RuntimeException('Cannot save conversion progress.'); }
        if ($this->observer) { ($this->observer)($job); }
    }
    public function lock(string $name, bool $nonblocking = false) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $name)) { throw new \RuntimeException('Invalid lock name.'); }
        $handle = fopen($this->root . '/' . $name . '.lock', 'c+');
        if (!$handle) { throw new \RuntimeException('Cannot lock storage.'); }
        @chmod($this->root . '/' . $name . '.lock', 0600);
        if (!flock($handle, LOCK_EX | ($nonblocking ? LOCK_NB : 0))) { fclose($handle); return false; }
        return $handle;
    }
    public function limit(string $key, int $maximum, int $window = 600): bool {
        $path = $this->root . '/limits/' . hash('sha256', $key) . '.json';
        $handle = fopen($path, 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) { throw new \RuntimeException('Cannot apply request limits.'); }
        @chmod($path, 0600);
        try {
            $record = json_decode(stream_get_contents($handle) ?: '{}', true) ?: [];
            $bucket = (int)floor(time() / $window);
            if (($record['bucket'] ?? -1) !== $bucket) { $record = ['bucket' => $bucket, 'count' => 0]; }
            if ($record['count'] >= $maximum) { return false; }
            $record['count']++;
            rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($record)); fflush($handle);
            return true;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    public function active(): int {
        $total = 0;
        foreach (glob($this->root . '/jobs/*/job.json') ?: [] as $path) {
            $id = basename(dirname($path));
            if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { continue; }
            try { $job = $this->read($id); } catch (\Throwable $e) { continue; }
            $fresh = ($job['state'] ?? '') === 'queued' ? time() - ($job['created'] ?? 0) <= 30 : time() - ($job['heartbeat'] ?? 0) <= 45;
            if (in_array($job['state'] ?? '', ['queued', 'running'], true) && ($job['expires'] ?? 0) > time() && $fresh) { $total++; }
        }
        return $total;
    }
    public function cleanup(): void {
        $lock = $this->lock('cleanup', true);
        if (!$lock) { return; }
        try {
            $removed = 0;
            foreach (glob($this->root . '/jobs/*/job.json') ?: [] as $path) {
                if ($removed >= 50) { break; }
                $id = basename(dirname($path));
                try { $job = $this->read($id); } catch (\Throwable $e) { continue; }
                if (($job['expires'] ?? PHP_INT_MAX) >= time()) { continue; }
                $running = @fopen($this->dir($id) . '/worker.lock', 'c+');
                if (!$running || !flock($running, LOCK_EX | LOCK_NB)) { if ($running) { fclose($running); } continue; }
                self::remove($this->dir($id));
                flock($running, LOCK_UN); fclose($running); $removed++;
            }
            foreach (glob($this->root . '/limits/*.json') ?: [] as $path) {
                if (filemtime($path) < time() - 7200) { @unlink($path); }
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function remove(string $path): void {
        if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') { self::remove($path . '/' . $entry); }
        }
        @rmdir($path);
    }
}
