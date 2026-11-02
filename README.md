# Render Video Converter

Website: [Youtube 2 MP4](https://yt2mp4.live/)

Independent YouTube MP4/MP3 conversion code for yt2mp4.live: WordPress issues signed requests, and the Render backend uses its own yt-dlp and FFmpeg engine. No third-party conversion API or competitor private source is included.

## Files

| File or directory | Purpose |
| --- | --- |
| Dockerfile, render.yaml | Docker build and Free pilot service configuration |
| backend/, deploy/ | Authenticated queue, one conversion worker, signed downloads and process supervisor |
| render-wordpress-plugin.zip | Installable WordPress plugin |
| render-video-converter-package.zip | Complete source package with runtime, plugin source, installation ZIP and documentation |
| wordpress-theme-integration.zip | WordPress theme/content files, plus rollback notes |
| SHA256SUMS.txt | Checksums for the packages and plugin ZIP |

For a new installation, see START-HERE-ROMAN-URDU.md or RENDER-SETUP.md. Defaults start in setup mode until a real WordPress origin and generated shared secret are configured.

## Limits and security

Configured pilot limits are 20 conversion requests and 40 analyses per UTC day; videos up to two minutes; source MP4 qualities up to 360p; final files up to 20 MiB. MP3 choices are 128, 192 and 320 kbps. One request runs at a time, with up to six queued/running requests in total. Failed requests consume quota.

WordPress issues short-lived signed tickets. Visitors use the backend for queue status, cancellation and expiring downloads. The shared administrative secret stays at the two servers. /healthz exposes readiness only; /health requires the secret. Temporary output can disappear on server restart or sleep. No paid database, disk or separate worker is configured.

Use videos you own or are authorized to download. Private, protected, restricted or live sources are not supported. No credentials, account cookies or production secret values are included in the packages.
