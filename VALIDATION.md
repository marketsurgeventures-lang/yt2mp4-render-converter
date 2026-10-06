# Validation

## Live deployment checks — 6 October 2026

**Passed:** actual Render Docker build, nginx/PHP-FPM/worker startup and Free service deployment. The Docker build syntax-checked all seven PHP runtime files. The authenticated WordPress health report passed worker, storage, FFmpeg, Node and yt-dlp checks. WordPress plugin/theme/content integration and the public paused notice were verified through the real site.

**Failed:** actual YouTube metadata/source extraction for the authorized Blender Sintel trailer, with “Sign in to confirm you’re not a bot.” No real YouTube MP4/MP3 conversion, signed output download or playback test has succeeded. Public requests remain disabled. See [DEPLOYMENT-STATUS.md](DEPLOYMENT-STATUS.md).

**Still unverified:** live successful source access, Free memory/CPU behavior under conversions, daily throughput and sustained reliability. Readiness/engine checks and a successful Docker deployment do not establish those outcomes. The fixture test suite below was not rerun for the latest diagnostic/documentation revisions.

## Historical local checks — 3 October 2026

The original package passed **83 local checks**: 15 WordPress unit checks, 29 backend unit checks, 9 runtime-configuration checks and 30 browser/HTTP/FFmpeg integration checks. Static checks passed for all 14 PHP files, both JavaScript entry points and all four Python files. The locally parsed Blueprint contained one Free Docker web service; that local check did not validate its schema through Render's API.

The historical suite used the actual PHP queue daemon, authenticated HTTP endpoints, JavaScript frontend in jsdom and real FFmpeg/ffprobe on generated two-second media. The extractor returned fixture metadata/local media; a small adapter supplied WordPress APIs. No real source credentials were used.

Verified fixture behavior included MP4/H.264/AAC and MP3 conversion, resumable signed downloads, signature/ticket rejection, ownership checks, replay rejection, origin restrictions, FIFO queueing, queued/running cancellation, duration/quality limits, daemon heartbeat, retained-state browser recovery, safe title rendering, protected administrative diagnostics and public readiness without secrets. Configuration checks covered setup mode, private permissions, default caps, invalid secrets/limits and unsafe ports.

Those historical checks did not verify Docker, Render, a real WordPress installation or live YouTube access at the time. Actual deployment checks above now cover Docker/WordPress integration, but YouTube access remains unresolved. Worker recovery fixtures preserve the local filesystem and do not simulate Render erasing it.

## Reproduce developer checks

Extract the full package. Install PHP CLI 8.1+ with OpenSSL/POSIX/process support, Node.js 22+, Python 3, FFmpeg/ffprobe with libx264/libmp3lame, util-linux `setsid` and jsdom 26. These are developer checks, not tasks required on Hostinger.

From the package root:

```sh
npm install --prefix /tmp/msvcr-test-deps jsdom@26
chmod +x tests/fake-ytdlp.py
MSVC_JSDOM=/tmp/msvcr-test-deps/node_modules/jsdom python3 tests/run-tests.py
```

Optional `MSVCR_TEST_PHP` and `MSVC_TEST_NODE` select executables. Ports 8877/8878 must be available. Temporary test services/state are cleaned up afterward. The test extractor is excluded from production builds by `.dockerignore`; never configure production to use it. Live acceptance requires a real video you own or have permission to download and actual output playback.
