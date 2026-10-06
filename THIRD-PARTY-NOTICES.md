# Third-party notices

Original plugin/backend integration code is GPL-2.0-or-later; see LICENSE.txt. This source ZIP contains no third-party production binaries or real credentials. The Docker build downloads dependencies under their own licenses:

- yt-dlp/default companion dependencies: https://github.com/yt-dlp/yt-dlp
- EJS compatibility: https://github.com/yt-dlp/yt-dlp/wiki/EJS
- FFmpeg: https://ffmpeg.org/legal.html (the Debian build includes components such as libx264 with their applicable licenses)
- Node.js: https://github.com/nodejs/node/blob/main/LICENSE
- PHP: https://www.php.net/license/
- nginx: https://nginx.org/LICENSE
- Python: https://docs.python.org/3/license.html
- tini: https://github.com/krallin/tini/blob/master/LICENSE

Offline tests use jsdom (not a production dependency): https://github.com/jsdom/jsdom/blob/main/LICENSE.txt

The fake extractor and WordPress adapters are explicitly offline fixtures. The deployed backend uses real yt-dlp; the plugin runs inside real WordPress. Docker base images and operating-system packages retain their respective licenses. A dependency snapshot is written to /opt/msvcr/runtime-dependencies.txt inside the built image.
