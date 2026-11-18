# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Laravel 13 / PHP 8.4 API with one unauthenticated endpoint, `POST /nationalize`: it takes 1–5 names and returns each name's most probable country (and up to 5 ranked countries) using the Nationalize.io API, with a 24h memcached cache. Source documents, in order of authority: `intent/intent.md` (accepted) → `spec.md` (design decisions and rationale) → `plan.md` (implementation plan).

## Commands

The sandbox has no PHP; everything runs in Docker (`Dockerfile`, `compose.yaml`: Debian PHP CLI with `intl` + `memcached`, and a memcached service). Prefix commands with `docker compose run --rm app` (add `--no-deps` to skip starting memcached when it is not needed).

```bash
docker compose build                                   # once, or after Dockerfile changes
docker compose run --rm app composer install           # first time; then: cp .env.example .env && php artisan key:generate
docker compose up -d memcached                         # needed only for the @group memcached integration test
docker compose run --rm app php artisan test           # whole suite (~0.5 s)
docker compose run --rm app php artisan test --filter=NationalityCacheTest            # one class
docker compose run --rm app php artisan test --filter=test_entries_live_for_exactly_24_hours   # one test
docker compose run --rm --no-deps app vendor/bin/pint # format (Laravel Pint)
```

`MemcachedIntegrationTest` skips itself when no memcached is reachable. The suite never contacts the real Nationalize.io: `tests/TestCase.php` calls `Http::preventStrayRequests()`, so any request not covered by `Http::fake()` fails the test.

## Architecture

Request flow: `RejectMalformedJson` middleware (400 on bad JSON; runs first, globally) → `NationalizeRequest` (validate + normalize) → `NationalityLookup` (cache + batching) → `NationalizeClient` (one upstream call) → `NationalityResource` (output shape) → `NationalizeController`.

- `app/Support/NameNormalizer.php` is the single definition of "the same name": trim, Unicode NFC, collapse internal whitespace; the cache key is `nationalize:v1:` + `sha1(lowercased normalized name)`. The response `name` echoes the *trimmed input as sent*, not the normalized form.
- `NationalityLookup` dedupes by cache key, reads `Cache::many`, sends **only the misses in one batch**, writes each result for 24 h. A cache that throws is treated as a miss (fail open, warning logged without names). The cached value is the upstream record **without** the `name` field.
- `NationalizeClient` builds the query string itself (`name[]=a&name[]=b&apikey=…`; Laravel would otherwise emit `name[0]=`), retries at most once and only on connection errors/5xx, maps results back **by position** (count must match), and never lets the URL (which contains the API key) or a name reach an exception message or log.
- `UpstreamUnavailableException` carries the public status/message: 503 for outages, quota/rate limits and credential problems; 502 for invalid upstream data. A failed upstream call fails the whole request (no partial results).
- Routing: `routes/api.php` with `apiPrefix: ''` in `bootstrap/app.php` so the path is `/nationalize`. All errors render as JSON.
- Config: `NATIONALIZE_API_KEY`, `NATIONALIZE_BASE_URL`, `NATIONALIZE_TIMEOUT` in `.env` → `config/services.php`. `.env` is gitignored; `.env.example` documents the variables.

Things that are easy to get wrong: never log or return names, the API key or the upstream URL (`LoggingAndRedactionTest` guards this); do not add auth or rate limiting without updating `intent/intent.md` (both were explicitly declined); upstream quota is counted per name, so cache hits save money.

## Repository state

Remote `origin` → `https://github.com/artisanal-development/nationalize.git`. Working branch is `develop`; `main` had no commits when this was written.

## Environment notes

- This runs in a sandbox in direct mode: the host working tree is mounted here, so edits and commits show up on the host right away.
- The sandbox proxy handles GitHub authentication, so `git push` and `gh pr create` work from inside the sandbox even when `gh auth status` says you're not logged in.
