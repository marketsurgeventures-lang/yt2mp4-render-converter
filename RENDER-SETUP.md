> Current deployment status (6 October 2026): the existing Free backend and WordPress integration are configured, but live YouTube source extraction is blocked by verification. Public conversion remains disabled. See DEPLOYMENT-STATUS.md before following new-installation steps; do not create a duplicate service or reset the existing shared secret.

# Deploy the pilot on Render

This guide uses one Free Docker web service. The WordPress site stays on Hostinger. The package has not yet been built or deployed on Render.

## 1. Publish the source

Create a dedicated GitHub repository. Upload the **contents** of the extracted `render-video-converter` folder so `Dockerfile`, `render.yaml`, `.dockerignore`, `backend/` and `deploy/` are in the repository root. Prefer a private repository if your Render account can access it. Preserve `.dockerignore` and `.gitignore`; exclude real secrets, media and test output.

## 2. Create the web service

In Render, choose **New → Web Service**, connect that repository and set:

| Setting | Value |
| --- | --- |
| Name | A unique converter service name |
| Runtime | Docker |
| Region | Singapore, or your chosen available region |
| Instance type | **Free** |
| Dockerfile | `./Dockerfile` |
| Root directory | Blank when the source is at the repository root |
| Docker command | Blank; use the Dockerfile CMD |
| Health check path | `/healthz` |
| Auto deploy | Off |
| Initial environment | `MSVCR_ENABLED=0` |

Alternatively, create a Blueprint from the included `render.yaml`. Review that it creates exactly one **Free** web service. Its non-secret defaults match this guide. If using a Blueprint, later change its `MSVCR_ENABLED` value to `"1"` after configuration so a future sync does not restore setup mode. Never put the secret in the Blueprint.

Let Render build the image. The initial setup-mode health response is `{"ok":true,"configured":false}`. This deliberately means conversions are disabled; it is not a successful conversion test. Copy the actual HTTPS service URL shown by Render.

## 3. Configure and enable

Generate one secret on your own trusted computer using Python:

```sh
python3 -c 'import secrets; print(secrets.token_hex(32))'
```

Keep the resulting value private. Add these values in Render → Environment:

| Variable | Value |
| --- | --- |
| `WORDPRESS_ORIGIN` | Your exact WordPress home origin, e.g. `https://www.your-domain.example` |
| `MSVCR_SHARED_SECRET` | Your generated 64-character lowercase hexadecimal value |
| `MSVCR_ENABLED` | `1` |
| `MSVCR_DAILY` | `20` |
| `MSVCR_MAX_MINUTES` | `2` |
| `MSVCR_MAX_HEIGHT` | `360` |
| `MSVCR_MAX_MB` | `20` |

The origin has no page path, `/wp-admin`, port, query or trailing slash. Include `www` only if WordPress uses it. Render supplies `RENDER_EXTERNAL_URL`, which is used for download links automatically. For a configured custom backend domain, set `MSVCR_BACKEND_URL` to that HTTPS origin and use the same URL in WordPress.

Save and deploy the updated environment. `/healthz` should now return `{"ok":true,"configured":true}`. Logs should say that one conversion worker is configured. The image runs as an unprivileged user; its generated configuration and media are outside the public document root.

## 4. Install and test WordPress

Upload the separate **render-wordpress-plugin.zip**, or the full package's `wordpress/render-video-converter.zip`, via Plugins → Add New → Upload Plugin. Activate it.

Open Settings → Render Video Converter, enter the actual Render origin and the same secret, leave the daily allowance at 20, enable and save. Open the service's `/healthz` first, then click **Check Render connection**. Add `[render_video_converter]` to a page. Exclude `/wp-json/msvcr/v1/*` from caching and purge old page caches.

Test MP4 and MP3 with a short video you own or are authorized to download. Confirm playback, queue progress, cancellation and download expiry. The public health route and connection check do not prove live YouTube accessibility. Do not enable a public launch until this actual test passes.

## Hosting limits and cost

As checked on 3 October 2026, Render Free sleeps after 15 idle minutes and takes about a minute to wake. Restarts, redeploys and sleep erase local state: jobs, media and backend counters. Free services have no persistent disk or shell, may restart at any time, and share 750 monthly instance hours per workspace. Render can suspend unusually high service-initiated traffic. See [Free service documentation](https://render.com/docs/free).

Hobby workspaces include **5 GB/month** of outbound bandwidth. Downloads and other responses consume it. Above the allowance, Render bills connected payment methods; without one, it suspends services until the next month. Check usage in Billing. See [bandwidth documentation](https://render.com/docs/outbound-bandwidth).

Our 20-request daily setting does not meter monthly bytes, repeat downloads or other services. For example, 100 downloads/day of 5 MB each is roughly 15 GB over 30 days before other traffic. This is a limited pilot, with no proven capacity for 100–200 conversions/day and no zero-cost guarantee.

The frontend wakes the backend only during visitor actions. It does not run artificial keepalive traffic. Browser refresh can resume requests while state exists; after a container reset, start again. WordPress transients provide another quota layer but are not durable billing protection.

## Troubleshooting and updates

- **Setup mode:** confirm enabled is `1` and that the latest environment change deployed.
- **Configuration failure:** check the exact origin, secret format and numeric limits. Values outside supported ranges fail startup.
- **Connection timeout:** wake `/healthz`, wait for JSON, then repeat the WordPress check.
- **No readiness:** inspect build/runtime logs for missing binaries, permissions or a stopped daemon. Do not upgrade the plan to troubleshoot without deciding to pay.
- **Source fails:** inspect the sanitized error and test another authorized public source. Cloud IP blocking or upstream restrictions can prevent extraction; this package cannot promise access or bypass controls.
- **Quota or storage busy:** wait for cleanup or the next UTC day. A request accepted by WordPress can consume a slot even if the backend refuses it.
- **Stop conversions:** disable the WordPress plugin setting, then set Render's `MSVCR_ENABLED=0` and deploy. Environment changes interrupt current jobs.

The Docker build installs the current `yt-dlp[default]`; auto deploy is off. If source compatibility changes, clear the build cache and redeploy, then retest a permitted source. Never copy real environment files into the image. Consult [Docker deployment](https://render.com/docs/docker) and [Blueprint fields](https://render.com/docs/blueprint-spec) when changing deployment configuration.
