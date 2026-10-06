FROM node:22-bookworm-slim AS js-runtime
FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx-light ffmpeg python3-venv ca-certificates util-linux tini \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install -j1 pcntl posix \
    && python3 -m venv /opt/msvcr/venv \
    && /opt/msvcr/venv/bin/python -m pip install --no-cache-dir 'yt-dlp[default]' \
    && /opt/msvcr/venv/bin/python -m pip freeze > /opt/msvcr/runtime-dependencies.txt \
    && groupadd -g 10001 msvcr && useradd -u 10001 -g msvcr -M -s /usr/sbin/nologin msvcr \
    && mkdir -p /var/lib/msvcr /var/lib/msvcr-config /tmp/nginx \
    && chown -R msvcr:msvcr /var/lib/msvcr /var/lib/msvcr-config /tmp/nginx \
    && chmod 700 /var/lib/msvcr /var/lib/msvcr-config
COPY --from=js-runtime /usr/local/bin/node /usr/local/bin/node
WORKDIR /app
COPY backend /app/backend
COPY deploy /app/deploy
COPY deploy/php.ini /usr/local/etc/php/conf.d/99-converter.ini
RUN rm -f /usr/local/etc/php-fpm.d/*.conf
COPY deploy/fpm.conf /usr/local/etc/php-fpm.d/converter.conf
ENV MSVCR_CONFIG=/var/lib/msvcr-config/config.json \
    MSVCR_ENABLED=0 PORT=10000 NODE_OPTIONS=--max-old-space-size=96
USER msvcr
EXPOSE 10000
ENTRYPOINT ["/usr/bin/tini", "-g", "--"]
CMD ["python3", "/app/deploy/run.py"]
