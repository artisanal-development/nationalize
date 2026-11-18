# Implementation plan: POST /nationalize (Laravel)

## Context
`intent/intent.md` (accepted) and `spec.md` (Draft r3, all design decisions settled, upstream behaviour verified) describe a single unauthenticated endpoint that returns the most probable country per name via Nationalize.io, with a 24h memcached cache. The repo contains only `CLAUDE.md`, `intent/intent.md` and `spec.md` (branch `develop`, clean). This plan turns the spec into work items: files, order, and the tests that prove each requirement.

## Environment findings (checked now)
- The sandbox has **no PHP, Composer or memcached**. **Docker is available**, so the app will run in containers.
- `composer create-project` refuses a non-empty directory (`intent/`, `spec.md`, `CLAUDE.md` exist). Scaffold in a scratch directory inside a container, then move the files in.
- Pulling images and Composer packages goes through the firewall. Expect approvals for Docker Hub / `packagist.org` / `repo.packagist.org` (and `github.com` for Composer dists). Ask the user to approve when blocked.
- **Assumption:** use **Laravel Sail** (`laravel/sail`, `--with=memcached`) for the runtime: its PHP image includes the `memcached` and `intl` extensions the spec needs. Fallback if Sail cannot run here: a small `Dockerfile` (php-cli + `memcached` + `intl`) plus `docker-compose.yml` with a memcached service.

## Order of work
Each step ends green (`php artisan test`) and is a separate commit **only when the user asks for commits**.

### Step 0 — Documents (no code)
1. `spec.md` FR-2: change "a list (not an object)" to "a JSON array (not an object)". Keep "list" only as the name of Laravel's `list` rule in §6.1/§6.2. Update §4.1's file layout with the extra files below.
2. `intent/intent.md`: fill "Open questions" and copy in the spec §8 items; fix typos ("will a POST", "will used", "it's probability"). Needs author go-ahead since the intent is "accepted".

### Step 1 — Scaffold and environment
- Scaffold Laravel into a scratch dir via a Composer container, move into repo root without clobbering `CLAUDE.md`, `intent/`, `spec.md`.
- Add Sail with memcached. Files: `compose.yaml`, `.env.example` (add `NATIONALIZE_API_KEY=`, `NATIONALIZE_BASE_URL`, `NATIONALIZE_TIMEOUT=5`, `CACHE_STORE=memcached`, `MEMCACHED_HOST=memcached`), `phpunit.xml` (`CACHE_STORE=array`, fake key), `.gitignore` check (`.env` ignored).
- Remove unused scaffolding that adds surface (no DB use: set `DB_CONNECTION` to sqlite memory for tests only; no sessions/queues in the route).
- `bootstrap/app.php`: `withRouting(api: routes/api.php, apiPrefix: '')`.
- Smoke test: `vendor/bin/sail artisan test` passes; `sail artisan route:list` shows no `/api` prefix.

### Step 2 — Route, request validation, normalization (spec FR-1…FR-5, §6)
Files:
- `routes/api.php` — `Route::post('/nationalize', NationalizeController::class)`.
- `app/Support/NameNormalizer.php` — `trim` → `Normalizer::normalize(FORM_C)` → `preg_replace('/\s+/u', ' ')`; plus `cacheKey()` helper (`nationalize:v1:` + `sha1(mb_strtolower())`). Used by both the request and the lookup so the rules live in one place.
- `app/Http/Requests/NationalizeRequest.php` — rules from §6.1 (`array`, `list`, `min:1`, `max:5`, items `string`/`max:50`); `prepareForValidation()` normalizes only string items; `normalizedNames()` / `originalNames()` accessors.
- `app/Http/Middleware/RejectMalformedJson.php` — returns 400 `{"message":"Malformed JSON."}` when a JSON content type has a body that fails `json_decode` (Laravel otherwise treats it as empty → 422). Register for the route.
- `app/Http/Controllers/NationalizeController.php` — thin invokable; at this step returns a stub.
- `bootstrap/app.php` `withExceptions`: always render JSON for this route (`shouldRenderJsonWhen`).
Tests (`tests/Feature/NationalizeValidationTest.php`, data-provider matrix): see "Test map" rows V-*.

### Step 3 — Upstream client and output shaping (FR-7, FR-10, FR-12, §4.4–4.5)
Files:
- `config/services.php` — `'nationalize' => [key, base_url, timeout]`.
- `app/Exceptions/UpstreamUnavailableException.php` — carries HTTP status (503/502) and a safe public message; never contains the URL or key.
- `app/Services/NationalizeClient.php` — `fetch(array $names): array`. Always `name[]`; `Http::timeout()->acceptJson()`; retry once, only for connection errors/5xx; map 401/402/429/5xx/timeouts → 503, 422/malformed/count mismatch → 502; wrap a bare object; map by position and verify count; catch `ConnectionException`/`RequestException` and rethrow sanitized (strip `apikey`); log low `X-Rate-Limit-Remaining` without names.
- `app/Http/Resources/NationalityResource.php` — sort by raw probability desc (stable), take 5, `round(p*100).'%'`, null/`[]` for unknown names.
- `app/Services/NationalityLookup.php` — at this step, no cache: dedupe by normalized name, one client call, rebuild in input order.
- `app/Http/Controllers/NationalizeController.php` — validate → lookup → resource; `bootstrap/app.php` renders `UpstreamUnavailableException` as `{"message": …}` with its status.
Tests: rows C-*, O-*, E-*.

### Step 4 — Cache layer (FR-6, FR-8, §4.3)
- Extend `NationalityLookup`: `Cache::many()` on hashed keys, fetch misses only, `Cache::put(..., now()->addHours(24))` of the record **without** `name`; failures never cached; both cache read and write wrapped in try/catch → treat as miss, `Log::warning` with no names (fail open).
- No new files. Optional: `app/Services/NationalityCache.php` if the try/catch makes `NationalityLookup` hard to read.
Tests: rows K-*.

### Step 5 — Hardening and logging (FR-9, FR-11, §7)
- Confirm no code path logs names or the key; `APP_DEBUG=false` default in `.env.example` for production notes; no Telescope/Debugbar installed.
- Log-capture and error-body tests (rows S-*).

### Step 6 — Wrap-up
- Update `CLAUDE.md` "Current state" with real commands: `sail up -d`, `sail artisan test`, single test via `--filter`, lint (`sail bin pint`), plus a short architecture overview. Add a README only if asked.
- Run `/security-review` and `/code-review` on the diff before any PR.

## Test map (PHPUnit feature tests with `Http::fake()` and the `array` cache; none touch the live API)
| Rows | Proves | Test |
|---|---|---|
| V-1…V-12 | 0 names, 6 names, missing `names`, string instead of array, object `{"a":"x"}`, items `""`, `"  "`, `1`, `null`, `true`, `[]`, 51-char name → 422; 50-char name and Unicode (`Núñez`, `李`) → 200 | `NationalizeValidationTest` |
| V-13 | Malformed JSON → 400, not 422 | same |
| N-1…N-4 | `"  Mario "` trimmed; `"de  la Cruz"` collapsed; composed/decomposed `é` same key; `Mario`/`mario` → one upstream name, two results, response `name` echoes the trimmed input | `NormalizationTest` + unit `NameNormalizerTest` |
| C-1 | Request URL has `name[]=…&apikey=…`, one call for multiple misses | `NationalizeEndpointTest` |
| C-2 | `["Mario","Mario"]` → 2 results, one `name[]` | same |
| O-1…O-4 | Output exactly matches §5.2; probabilities descending and `"83%"` format; ≤5 countries; ties keep upstream order; input order preserved with duplicates | same + unit `NationalityResourceTest` |
| O-5 | Unknown name (`country: []`, `count: 0`) → nulls and `[]`, 200 | same |
| K-1 | Second identical request sends zero upstream calls (`Http::assertNothingSent`) | `NationalityCacheTest` |
| K-2 | Partial hit sends only missing names; results land on the right inputs | same |
| K-3 | TTL: entry present at 23h59m, gone at 24h+ (`travelTo`) | same |
| K-4 | Upstream 500 then success: failure not cached, second call goes upstream | same |
| K-5 | Cached value has no `name` field | same |
| K-6 | Cache store throws on read/write → request still 200 via upstream; warning logged without names | same (custom failing store via `Cache::extend`) |
| E-1 | Upstream failure with some names cached → whole request 503 (no partial) | `UpstreamErrorTest` |
| E-2 | Result count mismatch / malformed record → 502 | same |
| E-3 | 401/402 → 503; 422 → 502; 429 (both variants) → 503, `Retry-After` passed through | same |
| E-4 | 429/4xx never retried; 5xx and `ConnectionException` retried at most once | same (`Http::assertSentCount`) |
| E-5 | Bare-object upstream response is wrapped and handled | same |
| S-1 | With `Log::spy`/log file capture: no submitted name and no `apikey` string appears in logs or in any error body, including the 503/502 paths | `LoggingAndRedactionTest` |
| S-2 | Responses are JSON (never HTML) for 400/422/502/503/500 | same |
| U-1 | Unit: `NameNormalizer` cache key stable across case and whitespace, differs across different names | `NameNormalizerTest` |

Optional, not part of the suite: one manual smoke run against the real API with a real key (the keyless allowance was 10 names and is exhausted for ~4h), and one integration test against real memcached in Sail (`@group memcached`, skipped when the store is unavailable). The two untested live questions from spec §9 (reversed-order batch, non-ASCII case folding) can be checked then.

## Critical files
`routes/api.php`, `bootstrap/app.php`, `app/Http/Requests/NationalizeRequest.php`, `app/Http/Middleware/RejectMalformedJson.php`, `app/Support/NameNormalizer.php`, `app/Services/NationalizeClient.php`, `app/Services/NationalityLookup.php`, `app/Http/Resources/NationalityResource.php`, `app/Exceptions/UpstreamUnavailableException.php`, `config/services.php`, `.env.example`, `phpunit.xml`, `compose.yaml`, `CLAUDE.md`. There is no existing code to reuse; Laravel built-ins used: `Http` client, `Cache::many/put`, `FormRequest`, `JsonResource`, `Http::fake()`.

## Verification (end-to-end)
1. `sail up -d` then `sail artisan test` — all rows above pass.
2. `sail artisan route:list` — `POST nationalize` with no `/api` prefix.
3. Manual with the real key from `.env`, memcached running: `curl -s -X POST localhost/nationalize -H 'Content-Type: application/json' -d '{"names":["Mario","nguyen","Mario"]}'` → 3 results; repeat → no upstream call (check `X-Rate-Limit-Remaining` unchanged or app log); stop memcached → still 200; submit bad bodies → 422/400 JSON.
4. `grep -ri apikey storage/logs` after the above — no key or names.
5. `/security-review` and `/code-review` on the final diff.

## Open items that do not block coding
P1 privacy sign-off and O1 audit-logging, per spec §9. They affect rollout, not implementation.
