# gaeld-eval — Gäld Community Edition deployment (GlaStar Flyers evaluation)

Container build + Compose stack for a self-hosted [Gäld](https://gaeld.ch)
Community Edition instance, used to evaluate Gäld as a cashctrl replacement
for the GlaStar Flyers flying club. Deployed on Coolify / Traefik at
`gaeld.tachly.app`.

Upstream ships only a Laravel Sail dev compose, so this repo provides a
production-ish setup:

- **`Dockerfile`** — multi-stage: fetch a pinned Gäld release tarball
  (`GAELD_VERSION`), `pnpm build` the assets, `composer install`, then a
  `php-fpm` + `nginx` + `supervisor` runtime with `tesseract-ocr` for receipt
  OCR. `laravel/tinker` is kept in the image for evaluation bootstrapping.
- **`docker-compose.yml`** — `web` (nginx + php-fpm), `worker` (Horizon),
  `postgres:16`, `redis:7`, `getmeili/meilisearch`.
- **`docker/`** — nginx, php-fpm pool, php.ini, supervisord, entrypoint.

The `web` container entrypoint runs `php artisan gaeld:install --no-interaction`
on first boot (idempotent: migrates every start, seeds the org + Swiss chart
of accounts only when none exists).

## Local run

```bash
cp .env.example .env      # fill APP_KEY, DB_PASSWORD, MEILI_MASTER_KEY
docker compose up -d --build
docker compose exec web curl -fsS http://localhost:8080/api/v1/
```

## Notes

- `APP_KEY` **must** be set (entrypoint aborts otherwise).
- Bind the domain to the `web` service on port `8080`.
- `TRUSTED_PROXIES=*` is safe only because the container is reachable only
  through the proxy.
