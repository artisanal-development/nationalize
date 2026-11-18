# Running the tests manually

Run these from the repo root. The sandbox has no PHP, so everything goes through Docker.

## One-time setup

```bash
docker compose build
docker compose run --rm app composer install
cp .env.example .env && docker compose run --rm app php artisan key:generate
```

If `.env` already exists, skip the `cp` and `key:generate` steps. If `vendor/` is already installed, skip `composer install`.

## Run the whole suite

```bash
docker compose run --rm app php artisan test
```

It takes about half a second. The tests use a fake upstream and an in-memory cache. They never contact Nationalize.io and never use your API key.

## Run part of it

```bash
# one test class
docker compose run --rm app php artisan test --filter=NationalityCacheTest

# one test
docker compose run --rm app php artisan test --filter=test_entries_live_for_exactly_24_hours

# stop at the first failure
docker compose run --rm app php artisan test --stop-on-failure
```

## Real memcached test

`MemcachedIntegrationTest` is skipped unless memcached is running. A plain `docker compose run --rm app …` starts it automatically. If you add `--no-deps`, start it yourself first:

```bash
docker compose up -d memcached
```

Shut everything down afterwards with `docker compose down`.

## Format check (optional)

```bash
docker compose run --rm --no-deps app vendor/bin/pint --test
```

Drop `--test` to fix formatting instead of only reporting problems.

## Calling the live API

That is not part of the test suite, because tests are blocked from reaching the network (`Http::preventStrayRequests()` in `tests/TestCase.php`). To try the real endpoint, start the development server. It uses the `NATIONALIZE_API_KEY` from your `.env`:

```bash
docker compose up -d serve          # listens on http://localhost:18080
```

Then, in another terminal:

```bash
curl -X POST localhost:18080/nationalize \
  -H 'Content-Type: application/json' \
  -d '{"names":["Michael","nguyen"]}'
```

Stop it with `docker compose down`. Logs: `docker compose logs -f serve`, and `storage/logs/laravel.log` for application warnings.

**Port already in use** (`Bind for 0.0.0.0:18080 failed: port is already allocated`): something else on the machine is using that port. Pick another host port with `APP_PORT`; the port inside the container stays 8000:

```bash
APP_PORT=9000 docker compose up -d serve     # then use localhost:9000
```

To see what holds a port: `docker ps` (another container), or `lsof -i :18080` on macOS/Linux. A container left over from an earlier run can be removed with `docker compose down`.

**`curl: (56) Recv failure: Connection reset by peer`** while `docker compose ps` shows `serve` running and `docker compose logs serve` shows no request: the connection never reached PHP, so another process on the machine is answering on that port (for example a forwarder left over from `sbx port publish`, or another app on 8080). Check with `lsof -nP -iTCP:<port> -sTCP:LISTEN`, or just choose another port with `APP_PORT`.

**Reaching it from your host machine.** If you run these commands inside the sandbox, the port is only reachable from within it. To open it from the host, publish it from the host side: `sbx port publish <sandbox-name> 18080:18080/tcp`. Use a different host port if 18080 is busy there too.

Each real call spends Nationalize.io quota, one name at a time. Cache hits cost nothing, so repeating the same request is free for 24 hours. To make sure a call really goes upstream, use a name you have not sent before.
