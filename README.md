# Render Video Converter

An independent WordPress converter with its own yt-dlp and FFmpeg engine. Visitors paste a YouTube URL, inspect available qualities, choose MP4 or MP3, convert, and download. No third-party conversion API is used. The implementation does not contain a competitor's private source code or backend.

**Package status:** source prepared and 83 local checks passed on 3 October 2026. No GitHub repository, Render service or live WordPress installation has been created from this package yet. Docker builds and live source access still need deployment verification.

Start with **START-HERE-ROMAN-URDU.md** or **RENDER-SETUP.md**. The included `render.yaml` explicitly selects a single Free Docker web service and initially leaves conversions disabled until the real WordPress origin and shared secret are configured.

## What is included

| File or folder | Purpose |
| --- | --- |
| `Dockerfile`, `render.yaml` | Build and service configuration for Render |
| `backend/` | Queue, one conversion daemon, request authentication and private downloads |
| `deploy/` | Runtime configuration and nginx/PHP-FPM/worker supervisor |
| `wordpress/render-video-converter.zip` | Installable WordPress plugin |
| `wordpress/render-video-converter/` | Plugin source |
| `VALIDATION.md`, `tests/` | Test results, scope and reproduction instructions |

The default pilot accepts 20 conversion requests and 40 analyses per UTC day, videos up to two minutes, available MP4 source qualities up to 360p, and final files up to 20 MiB. MP3 choices are 128, 192 and 320 kbps; a higher output bitrate does not improve the original source's fidelity. One request runs at a time, with up to six active or queued requests in total.

WordPress issues short-lived signed tickets. The visitor talks directly to the Render backend for queue status, cancellation and downloads. The shared administrative secret stays on the two servers. `/healthz` exposes only readiness; `/health` requires the secret.

No paid database, persistent disk or separate Render worker is configured. Hosting quotas and reliability limits are explained in `RENDER-SETUP.md`. The request allowance is not a bandwidth budget or a guarantee of 100–200 daily conversions at no cost.

Use only videos you own or are authorized to download. Live streams and unavailable, protected or restricted sources can fail. This package does not bypass DRM, CAPTCHA, sign-in requirements or source restrictions.
