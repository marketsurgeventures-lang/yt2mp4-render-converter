# Validation — 3 October 2026

**83 local checks passed:** 15 WordPress unit checks, 29 backend unit checks, 9 runtime-configuration checks, and 30 browser/HTTP/FFmpeg integration checks.

Static checks also passed for all 14 PHP files, both JavaScript entry points and all four Python files. The locally parsed Blueprint contains one Free Docker web service; its schema has not been validated by Render's API.

The integration suite used the actual PHP queue daemon, authenticated HTTP endpoints, JavaScript frontend in jsdom and real FFmpeg/ffprobe on generated two-second media. The extractor returned fixture metadata and local media; a small adapter supplied WordPress APIs. No real source credentials were used.

Verified behavior includes MP4/H.264/AAC and MP3 conversion, resumable signed downloads, forged signature/ticket rejection, ownership checks, replay rejection, origin restrictions, FIFO queueing, queued/running cancellation, duration and quality limits, daemon heartbeat, retained-state browser recovery, safe title rendering, protected administrative diagnostics, and public readiness without exposing secrets. Configuration checks cover setup mode, private file permissions, default caps, invalid secrets, invalid limits and unsafe port input.

**Not verified:** Docker image build, real nginx/PHP-FPM container startup, Render deployment, live YouTube access, a real WordPress installation, Render Free memory/CPU behavior or daily throughput. Passing local tests does not establish cloud compatibility or production reliability. Worker recovery tests preserve the local filesystem; they do not simulate Render erasing that filesystem.

## Reproduce local checks

Install PHP CLI 8.1+ with OpenSSL, POSIX and process support; Node.js 22+; Python 3; FFmpeg/ffprobe with libx264/libmp3lame; util-linux `setsid`; and jsdom 26. These are developer checks, not actions required on Hostinger.

From the package root:

```sh
npm install --prefix /tmp/msvcr-test-deps jsdom@26
chmod +x tests/fake-ytdlp.py
MSVC_JSDOM=/tmp/msvcr-test-deps/node_modules/jsdom python3 tests/run-tests.py
```

Optional environment variables `MSVCR_TEST_PHP` and `MSVC_TEST_NODE` select alternate executables. Ports 8877 and 8878 must be available. The runner creates temporary state and terminates test services afterward.

The fixture extractor is confined to `tests/`, which `.dockerignore` excludes from production builds. Never configure the deployed service to use it. Live deployment verification must use a video you own or have permission to download and must confirm both MP4 and MP3 playback.
