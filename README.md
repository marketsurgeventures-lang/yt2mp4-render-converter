# Render Video Converter

Website: [Youtube 2 MP4](https://yt2mp4.live/)

Independent YouTube MP4/MP3 conversion code for yt2mp4.live: WordPress issues signed requests, and the Render backend uses its own yt-dlp and FFmpeg engine. No third-party conversion API or competitor private source is included.

**Current status — 6 October 2026: backend deployed; YouTube conversions unavailable.** The Free Docker service is configured and its worker/engine health checks passed. Actual YouTube extraction failed with a source verification requirement. Public conversion is disabled and the homepage displays a paused notice. A successful deployment is not a successful YouTube conversion test.

Read [DEPLOYMENT-STATUS.md](DEPLOYMENT-STATUS.md) for the live configuration, exact blocker and remaining acceptance checks, and [VALIDATION.md](VALIDATION.md) for historical versus live validation. The existing deployment does not need a duplicate service or a new shared secret.

## Files

| File or directory | Purpose |
| --- | --- |
| `Dockerfile`, `render.yaml` | Docker build and Free pilot service configuration |
| `backend/`, `deploy/` | Authenticated queue, one conversion worker, signed downloads and process supervisor |
| `render-wordpress-plugin.zip` | Unchanged installable WordPress plugin |
| `render-video-converter-package.zip` | Refreshed complete source package with current runtime, plugin source, installation ZIP, documentation and historical test suite |
| `wordpress-theme-integration.zip` | Original and saved current WordPress theme/content files, plus rollback notes |
| `DEPLOYMENT-STATUS.md`, `VALIDATION.md` | Actual deployment state, unresolved source access and validation scope |
| `SHA256SUMS.txt` | Checksums for the two refreshed packages and unchanged plugin ZIP |

For a new installation, see `START-HERE-ROMAN-URDU.md` or `RENDER-SETUP.md`. Defaults start in setup mode until a real WordPress origin and generated shared secret are configured. Public enablement requires an actual authorized-video conversion and download test.

## Limits and security

Configured pilot limits are 20 conversion requests and 40 analyses per UTC day; videos up to two minutes; source MP4 qualities up to 360p; final files up to 20 MiB. MP3 choices are 128, 192 and 320 kbps. One request runs at a time, with up to six queued/running requests in total. Failed requests consume quota. These limits are not a guarantee of 100–200 daily conversions, production reliability or zero monthly charges.

WordPress issues short-lived signed tickets. Visitors use the backend for queue status, cancellation and expiring downloads. The shared administrative secret stays at the two servers. `/healthz` exposes readiness only; `/health` requires the secret. Temporary output can disappear on server restart or sleep. No paid database, disk or separate worker is configured.

Use videos you own or are authorized to download. Private, protected, restricted or live sources are not supported. This implementation does not bypass sign-in, CAPTCHA, DRM or a source verification restriction. No credentials, account cookies or production secret values are included in the packages.
