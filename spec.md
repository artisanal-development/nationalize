# Spec: POST /nationalize — most probable country of origin per name

Source: `intent/intent.md` (A. Liang, status: accepted)
Status: **Draft r3** — design decisions settled with the author; remaining open items are in §9.

**Revision note (r3):** Decisions made with the author are folded in and marked **[Decided]**. Brand/security/UX-skill review text was removed (no such policies exist for this repo). The per-IP throttle proposed in r2 was **declined**. Items that go beyond the literal wording of `intent.md` are listed in §8 so they can be copied back into the intent.

---

## 1. Summary

A new Laravel application exposing one unauthenticated endpoint, `POST /nationalize`. It accepts 1–5 names, resolves each name's country probabilities via the Nationalize.io API (using a memcached cache with a 24h TTL to avoid repeat calls), and returns for each name the ranked country list (max 5), plus the top country and its probability. The repo has no existing code, so conventions are Laravel defaults.

## 2. Goals / Non-goals

**Goals**
- Validated, batched lookup of up to 5 names per request.
- Minimize upstream calls via memcached (24h TTL); never call upstream for a name that is cached.
- Stable, documented JSON contract.

**Non-goals**
- Authentication/authorization and rate limiting (both explicitly out of scope; see §7 for the residual risk).
- Any persistence other than the cache (no database, no result storage).
- UI, brand theming, localization.

## 3. Functional requirements

| ID | Requirement |
|----|-------------|
| FR-1 | `POST /nationalize` accepts a JSON body `{"names": [string, ...]}`. |
| FR-2 | `names` is required, a JSON array (not an object), 1–5 items. |
| FR-3 | Every item must be a string that is non-empty after trimming and at most **50 characters** **[Decided]**. Non-strings (numbers, null, bool, arrays, objects) and empty/whitespace-only strings are rejected with 422. Validation is strict: no coercion before validation (§6.2). |
| FR-4 | Normalization **[Decided]**: each name is trimmed, Unicode-normalized to NFC, and has internal runs of whitespace collapsed to a single space. The normalized form is what is used for deduplication, cache keys and the upstream request. |
| FR-5 | Duplicates are permitted in the input. The response contains one result per input item, **in input order**, including duplicates. Items that normalize identically (e.g. `Mario`, `mario`, `"Mario  "`) are treated as the same name. |
| FR-6 | For each distinct normalized name, check memcached first. Only cache misses are sent upstream; a cached value is used instead of calling the API. If all names hit, no upstream call is made. |
| FR-7 | All cache-miss names of a request are sent in **one** upstream call: `GET https://api.nationalize.io/?name[]=a&name[]=b&apikey={key}`. A name is sent at most once per request. **[Decided]** The guarantee is per request only: two concurrent requests for the same uncached name may both call upstream (no locking). |
| FR-8 | Successful upstream results are written to memcached with TTL 24h (86400 s). Failures are never cached. |
| FR-9 | Apart from the cache, the system does **not** persist or retain results (no DB, files, queues, result logs). Logs must not include names (§7). |
| FR-10 | Per name, the response includes the ranked country list sorted by probability descending, truncated to 5, plus `most_probable_country` and `probability`. |
| FR-11 | The API key is read from `.env` (`NATIONALIZE_API_KEY`) via `config/services.php`; never hard-coded or committed. |
| FR-12 | **[Decided]** If the upstream call fails, the whole request fails with 503 (or 502 for invalid data), even if some names were cached. No partial results. |

## 4. Design

### 4.1 Stack and layout
- Laravel (current stable at scaffold time), `Http` client facade, `Cache` facade with the `memcached` store.
- Routing: `routes/api.php` → `POST /nationalize`. `api.php` adds an `/api` prefix by default, so set `->withRouting(apiPrefix: '')` in `bootstrap/app.php`. No session/CSRF stack applies on this route group.

```
app/Http/Controllers/NationalizeController.php   thin: validate → service → resource
app/Http/Requests/NationalizeRequest.php         validation + normalization
app/Http/Middleware/RejectMalformedJson.php      400 for malformed JSON bodies
app/Support/NameNormalizer.php                   trim/NFC/whitespace collapse + cache key
app/Services/NationalityLookup.php               cache orchestration, batching, merge
app/Services/NationalizeClient.php               HTTP client for Nationalize.io only
app/Http/Resources/NationalityResource.php       output shaping (percent strings, top-5)
app/Exceptions/UpstreamUnavailableException.php  mapped to 502/503
config/services.php                              'nationalize' => [key, base_url, timeout]
tests/Feature/NationalizeEndpointTest.php
tests/Unit/NationalityLookupTest.php
```

### 4.2 Request flow
1. `NationalizeRequest` validates and produces normalized names (§FR-4), keeping each original trimmed input for the response.
2. `NationalityLookup::lookup(array $names)`:
   1. Compute the cache key per distinct normalized name (§4.3).
   2. `Cache::many()` on the distinct keys → hits/misses.
   3. If misses exist, `NationalizeClient::fetch($missNames)` makes one batched call.
   4. Write each fetched result with `Cache::put($key, $payload, now()->addHours(24))`.
   5. Rebuild results in original input order (duplicates map to the same payload).
3. The resource formats and returns JSON.

### 4.3 Cache design
- Store: memcached (`CACHE_STORE=memcached`, `MEMCACHED_HOST`, etc. in `.env`).
- **Key:** `nationalize:v1:` + `sha1(mb_strtolower(normalized_name))`. Hashing is required because memcached keys cannot contain whitespace/control characters and are limited to 250 bytes. The `v1` prefix lets us invalidate on format change. Keys are case-insensitive **[Decided]**.
- **Value:** the raw upstream record for that name (`count` and `country[]` with numeric probabilities) **without the `name` field**, so no plaintext name is stored in the cache (§7.4). Display format can change without invalidating the cache.
- The response `name` echoes the **trimmed input as sent, original case** **[Decided]**. It is attached from the request, not from the cache.
- The cache also stores "no data" answers (empty `country` array): they are valid answers and re-asking costs quota.
- **Cache unavailable [Decided: fail open]:** if memcached errors, treat as a miss and call upstream; log a warning without names. Quota may be consumed faster during an outage.

### 4.4 Upstream client
- `Http::timeout(5)->acceptJson()->get($url)` where the client **builds the query string itself** (`name[]=a&name[]=b&apikey=…`, each value `rawurlencode`d). Passing `['name' => [...]]` to `get()` would make Laravel emit indexed `name[0]=a` keys instead of the documented `name[]=`. Guzzle sends the brackets percent-encoded (`name%5B%5D=a`); a live call confirmed the real API accepts that form. Timeout is config-driven (`services.nationalize.timeout`).
- **Retry only on connection errors and 5xx**, with at most one retry and short backoff. Never retry 4xx/429 (that spends quota on calls that will fail).
- **Result mapping [Decided]: by position.** Upstream docs state "the response is an array in the same order as the input" for batch requests. If the length differs from the number of names sent, or any record is malformed, throw → 502. Always send `name[]` (even for one name): live tests confirmed `name[]=x` returns a one-element array, while plain `name=x` returns a bare object. Still treat a bare object defensively (wrap it) in case upstream changes.
- Upstream errors are JSON `{"error": "..."}` with these mappings (upstream messages are never forwarded to callers):
  - 401 `Invalid API key` / 402 `Subscription is not active` → our configuration problem: log an error (no key), respond 503.
  - 422 `Invalid 'name' parameter` → should not occur after our validation; respond 502 and log.
  - 429 `Request limit reached` (quota or per-IP limit exhausted) → 503. 429 `Request limit too low to process request` (observed live when the remaining quota is smaller than the number of names in the batch, e.g. 2 remaining, 3 names sent; not consumed) → 503. Neither is retried (see above); pass `Retry-After` through if present. Note that a smaller batch might still succeed when this occurs, but the spec does not split batches.
  - Other non-2xx, timeout or malformed payload → `UpstreamUnavailableException` (§5.3).
- Upstream sends `X-Rate-Limit-Remaining` / `-Limit` / `-Reset` on every response (counted in names). Log a warning without names when remaining quota is low.
- **The API key travels in the query string** (dictated by the upstream). Exception messages, `Http` debug output and any request logging **must redact the `apikey`**. Laravel's `RequestException` messages include the URL, so catch and re-throw sanitized exceptions.

### 4.5 Output shaping
- `probability` is a string percentage: `round(p * 100)` + `"%"`, integer, matching the intent example `"83%"` **[Decided]**.
- Sort by raw numeric probability descending (ties: upstream order, stable) **before** rounding, then take the first 5. `most_probable_country`/`probability` come from the first element.
- Name with no upstream data **[Decided]**: `most_probable_country: null`, `probability: null`, `countries: []`, still HTTP 200.

## 5. API contract

### 5.1 Request
```http
POST /nationalize
Content-Type: application/json
Accept: application/json

{ "names": ["Mario", "  nguyen ", "Mario"] }
```

### 5.2 Success — `200 OK`
```json
{
  "results": [
    {
      "name": "Mario",
      "most_probable_country": "IT",
      "probability": "83%",
      "countries": [
        { "country_id": "IT", "probability": "83%" },
        { "country_id": "AR", "probability": "6%" }
      ]
    }
  ]
}
```
One entry per input name, in input order (duplicates included). `countries` has at most 5 entries.

### 5.3 Errors (consistent JSON, never HTML or stack traces)
| Status | When | Body |
|---|---|---|
| 422 | Validation failure (missing/empty/oversized list, non-string or blank item, name over 50 chars, non-JSON/empty body) | Laravel standard `{"message": "...", "errors": {"names.1": ["..."]}}` |
| 400 | Malformed JSON body | `{"message": "Malformed JSON."}` (**needs a custom handler**: by default Laravel treats malformed JSON as an empty body and returns 422) |
| 502 | Upstream returned invalid/unexpected data, or result count mismatch | `{"message": "Name lookup service returned an invalid response."}` |
| 503 | Upstream timeout/unreachable/rate-limited | `{"message": "Name lookup service is temporarily unavailable."}` |
| 500 | Anything else | Generic message; no internals |

There is no local 429: rate limiting is out of scope. Error messages must never include the API key, upstream URL, or the submitted names beyond field-level validation messages. Content-type handling beyond the above uses Laravel defaults (the spec does not require a 415).

## 6. Validation rules

### 6.1 Rules
```php
'names'   => ['required', 'array', 'list', 'min:1', 'max:5'],
'names.*' => ['required', 'string', 'max:50'],   // checked on the normalized value
```
Normalization runs in `prepareForValidation()` **only on string items**, so `123` or `null` still fail `string` rather than being coerced.

### 6.2 Laravel behaviours to handle and test
- `TrimStrings` and `ConvertEmptyStringsToNull` are global middleware in current Laravel and run before validation. Either rely on them knowingly (`""` becomes null and fails `required`) or exclude `names` from them; in both cases `"   "` must be rejected after trimming. Tests assert the outcome, not the mechanism.
- `{"names": {"a": "x"}}` (JSON object) must be rejected. PHP's `array` rule also accepts JSON objects, so Laravel's `list` rule is added to require a plain JSON array.
- `{"names": "Mario"}` (string, not array) → 422.
- Items that are `true`, `1`, `null`, `[]`, `{}` → 422.
- Unicode names such as `"Núñez"` and `"李"` are valid; emit as UTF-8 and URL-encode upstream. Composed and decomposed forms of the same name must produce the same cache key (NFC).
- The 50-character limit counts characters (not bytes), after normalization.

## 7. Security and privacy considerations
No organization security policy was supplied; these are generic best-practice measures.

1. **Secret handling:** API key only in `.env` (gitignored; commit `.env.example` with an empty `NATIONALIZE_API_KEY=`). Never log it; redact in exceptions (§4.4).
2. **Unauthenticated, unthrottled public proxy to a quota-limited keyed upstream [Decided].** The author declined a rate limit. Residual risk: anyone can spend the Nationalize.io quota, and the caches only partly mitigate this. This risk is accepted and should be revisited if quota is exhausted or costs appear; a throttle can be added later without changing the contract (it should then use a store other than memcached so it survives a cache outage).
3. **Cache poisoning / key abuse:** hashed keys prevent key injection and length errors; values are only written from validated upstream responses.
4. **Privacy / no-retention:** names can be personal data. Do not log request bodies or names; ensure exception reporting, Telescope/Debugbar and access logs do not capture them; set `APP_DEBUG=false` in production. Cached values omit the name (§4.3), and keys are hashed. **Names from anonymous users are sent to Nationalize.io, and cached entries live 24h; this needs sign-off from the privacy owner (§9, P1).**
5. **Transport:** terminate TLS in front of the app; upstream calls over HTTPS with certificate verification on (Laravel default).
6. **Memcached exposure:** bind to a private interface only; memcached has no auth by default.
7. **Input bounds:** max 5 items and 50 characters per name bound the upstream URL size. Also set a request body size limit at the web server.

## 8. Differences from / additions to `intent.md`
These were decided with the author and should be copied into `intent.md` (it has no open-questions content yet):
- Per-name maximum length of 50 characters.
- Name normalization: NFC, collapsed internal whitespace, case-insensitive cache key (the intent only requires trimming).
- Unknown names return null fields and an empty `countries` array.
- All-or-nothing failure (503/502) when upstream fails; fail-open when memcached fails.
- Per-request (not cross-request) "at most once" guarantee.
- Integer percent strings (as in the intent example); no numeric probability field.
- Explicitly **not** added: authentication and rate limiting.
- Intent typos to fix: "will a POST", "will used", "it's probability".

## 9. Remaining open items
**Verification against Nationalize.io** (official docs at nationalize.io/documentation/api/reference plus live keyless calls, 2026-11-18):
- **V1 (closed):** Docs state batch responses are an array in input order. Live: `name[]=michael&name[]=nguyen&name[]=schmidt` returned records in that order; `name[]=michael` returned a one-element array; `name=michael` returned a bare object. With an API key, a forward batch (`nguyen,michael,schmidt`) and its reverse (`schmidt,michael,nguyen`) each came back in exactly the order sent, so position mapping is verified.
- **V2 (closed):** Max 100 names per request (our cap of 5 is well within it). Quota is counted **per name**, monthly; a batch of N consumes N. Free-tier limits and pricing are on a separate pricing page and were not retrieved; a 402 implies a paid subscription for keyed use. Cache hits therefore save quota directly.
- **V3 (closed):** Live: `Michael`, `michael` and `MICHAEL` returned identical counts and probabilities, and each record echoes `name` exactly as sent. Non-ASCII folds the same way: `Émile` and `émile` both returned count 29017 and identical countries. The case-folded cache key is supported.
- **Live end-to-end run (keyed, 2026-11-18):** quota counted per name as documented. A request with `["Michael","  nguyen ","michael","Garcia"]` spent 3 names (the two `Michael` spellings were one); an immediate repeat in different case spent 0; a request adding one new name spent 1. An unknown name returned null fields; a wrong key gave 503 with a logged error and no key in logs or responses.
- **Also observed live:** an unknown name returns `{"count":0,"name":"...","country":[]}` (matches §4.5). Without an API key the free allowance was only **10 names per window** (`X-Rate-Limit-Limit: 10`, reset about 4 hours), counted per name; so integration tests must use `Http::fake()` and never hit the live API.
- **V4 (closed):** The API key is passed only as an `apikey` query parameter; no header alternative is documented. Conflict C.4 (key in URL) therefore stays, and redaction (§4.4) is mandatory.

**Owner decisions:**
- **P1:** Privacy sign-off for sending names to Nationalize.io and caching results for 24h.
- **O1:** If the organization later requires audit logging, FR-9 (no logging of names) conflicts with it; the likely resolution is logging hashes only.

## 10. Testing plan
Feature tests use `Http::fake()` and the `array` cache driver (plus one integration test against real memcached in CI if available).

- Happy path, single and multiple names; output exactly matches §5.2 (percent strings, descending order, ≤5 countries).
- Normalization: `"  Mario "` → `Mario`; `"de  la Cruz"` collapses to one space; composed/decomposed `é` share a key; `Mario` and `mario` produce one upstream name and two results.
- Duplicates: `["Mario","Mario"]` → 2 results, **one** upstream `name[]`.
- Cache hit: second request makes **zero** upstream calls (`Http::assertNothingSent`).
- Partial hit: only the missing names are sent; position mapping lines results up with the right inputs.
- TTL: entry expires at 24h (`Carbon::setTestNow` / travel).
- Failures not cached: upstream 500 then success → second call goes upstream.
- Upstream failure with some names cached → whole request 503 (FR-12).
- Position mapping: result count mismatch → 502.
- Retry policy: 4xx/429 are not retried; 5xx/connection errors retry at most once.
- Validation matrix from §6.2 (0 names, 6 names, `""`, `"  "`, `1`, `null`, nested arrays, object shape, 51-character name, malformed JSON → 400).
- Unknown name (empty `country`) → null fields, empty `countries`, 200.
- Memcached down → falls back to upstream, request succeeds, warning logged without names.
- Log-capture test: no name and no `apikey` string appears in logs or error bodies.

## 11. Delivery notes
- Implemented on Laravel 13 / PHP 8.4. The dev sandbox has no PHP, so development and tests run in Docker (`Dockerfile`, `compose.yaml`: Debian PHP CLI with `intl` and `memcached`, plus a memcached service). Commands are in `CLAUDE.md`.
- Requires the PHP `memcached` and `intl` extensions and a memcached instance.
- Suggested slices: (1) scaffold + route + validation + tests, (2) client + output shaping, (3) cache layer, (4) redaction and log tests.
