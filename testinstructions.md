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

That is not part of the test suite, because tests are blocked from reaching the network (`Http::preventStrayRequests()` in `tests/TestCase.php`). To try the real endpoint, start the server with the `NATIONALIZE_API_KEY` from your `.env` and call it:

```bash
docker compose run --rm -p 8000:8000 app sh -c 'cd public && php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
# in another terminal:
curl -X POST localhost:8000/nationalize -H 'Content-Type: application/json' -d '{"names":["Michael","nguyen"]}'
```

To reach it from your host, publish port 8000 from the sandbox (`sbx port publish <sandbox-name> 8000:8000/tcp`). Each real call spends Nationalize.io quota, one name at a time. Cache hits cost nothing.
