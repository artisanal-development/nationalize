# Spec: POST /nationalize — most probable country of origin per name

Source: `intent/intent.md` (A. Liang, status: accepted)
Status: **Draft r2 for engineering review** — revised after `intent.md` was corrected (duplicate-name and no-persistence sentences). Open items in §10 still need an owner decision.

**Revision note (r2):** the two garbled intent constraints are now unambiguous and confirm the earlier interpretation: (1) a name is sent to Nationalize.io *at most once* (de-duplicated within a request; cached names are not sent at all), and (2) apart from the memcached cache, results are *not* persisted or retained. FR-6/FR-7/FR-9 are reworded to cite them, and former §10.A is removed.

---

## 0. Read this first: what this spec could and could not be checked against

The request asked that this spec conform to "our brand guidelines, security policies and UX standards" using available skills. I checked, and **none of those exist in this environment**:

- The repository contains only `CLAUDE.md` and `intent/intent.md`. There is **no existing codebase** (no commits on `main`, no Laravel scaffold), so "integration into our existing codebase" reduces to "scaffold a new Laravel app." There are no existing conventions to match.
- The available skills are tooling skills (artifact design, dataviz, code review, `security-review`, etc.). None encodes a brand guideline, a security policy, or a UX standard. `security-review` reviews pending code changes; it is not a policy document.

Therefore **no claim of conformance to any brand, security or UX policy is made in this document.** Where I applied judgment instead, it is labelled *"Assumption"* or *"Recommendation"* (generic good practice, e.g. OWASP-style), and §9 lists what must be reviewed against the real policies once someone supplies them. Brand and UX standards are largely not applicable (this is a headless JSON API with no UI); the one UX-adjacent surface is the API's error and response contract, which is specified in §5.

Because no conflicting policies were supplied, I also could not identify *policy-vs-policy* conflicts. The conflicts I did find are **within the intent itself** and between the intent and basic security practice; see §10.

---

## 1. Summary

A new Laravel application exposing one unauthenticated endpoint, `POST /nationalize`. It accepts 1–5 names, resolves each name's country probabilities via the Nationalize.io API (using a memcached cache with a 24h TTL to avoid repeat calls), and returns for each name the ranked country list (max 5), plus the top country and its probability.

## 2. Goals / Non-goals

**Goals**
- Validated, batched lookup of up to 5 names per request.
- Minimize upstream calls via memcached (24h TTL); never call upstream for a name that is cached.
- Stable, documented JSON contract.

**Non-goals**
- Authentication/authorization (explicitly out of scope per intent).
- Any persistence other than the cache (no database, no result storage).
- UI, brand theming, localization.

## 3. Functional requirements

| ID | Requirement |
|----|-------------|
| FR-1 | `POST /nationalize` accepts a JSON body `{"names": [string, ...]}`. |
| FR-2 | `names` is required, an array, 1–5 items. |
| FR-3 | Every item must be a non-empty string (after trimming). Non-strings (numbers, null, bool, arrays, objects) and empty/whitespace-only strings are rejected with 422. Validation must be strict: Laravel's default `ConvertEmptyStringsToNull` and `TrimStrings` middleware must not silently coerce input before validation (see §6.2). |
| FR-4 | Leading/trailing whitespace is removed from each name before any cache or API use. |
| FR-5 | Duplicates are permitted in the input. The response contains one result per input item, **in input order**, including duplicates. |
| FR-6 | For each distinct normalized name, check memcached first. Only names with a cache miss are sent upstream; a cached value is used instead of calling the API. If all names hit, no upstream call is made. |
| FR-7 | All cache-miss names in a request are sent in **one** upstream call: `GET https://api.nationalize.io/?name[]=a&name[]=b&apikey={key}`. Per the intent, a name is sent **at most once** per request even if duplicated in the input. |
| FR-8 | Successful upstream results are written to memcached with TTL 24h (86400 s). Failures are never cached. |
| FR-9 | Per the intent, apart from the cache the system does **not** persist or retain results (no DB, files, queues, result logs). Request logging must not include names (see §7). |
| FR-10 | Per name, the response includes the full ranked country list sorted by probability descending, truncated to 5, plus `most_probable_country` and `probability`. |
| FR-11 | The API key is read from `.env` (`NATIONALIZE_API_KEY`) via `config/services.php`; never hard-coded or committed. |

## 4. Design

### 4.1 Stack and layout
- Laravel (current stable at scaffold time; PHP version per that release), `Http` client facade, `Cache` facade with the `memcached` store.
- Routing: `routes/api.php` → `POST /nationalize`. Note `api.php` adds an `/api` prefix by default; the intent specifies `/nationalize`, so register the route with the prefix removed (`->withRouting(apiPrefix: '')` in `bootstrap/app.php`) or place it in `web.php` with CSRF exemption. **Recommendation:** `api.php` with empty prefix (no session/CSRF stack, JSON-first error handling).

```
app/Http/Controllers/NationalizeController.php   thin: validate → service → resource
app/Http/Requests/NationalizeRequest.php         validation + normalization
app/Services/NationalityLookup.php               cache orchestration, batching, merge
app/Services/NationalizeClient.php               HTTP client for Nationalize.io only
app/Http/Resources/NationalityResource.php       output shaping (percent strings, top-5)
app/Exceptions/UpstreamUnavailableException.php  mapped to 502/503
config/services.php                              'nationalize' => [key, base_url, timeout]
tests/Feature/NationalizeEndpointTest.php
tests/Unit/NationalityLookupTest.php
```

### 4.2 Request flow
1. `NationalizeRequest` validates and produces trimmed names.
2. `NationalityLookup::lookup(array $names)`:
   1. Compute cache key per name (§4.3). Build the distinct set.
   2. `Cache::many()` on the distinct keys → hits/misses.
   3. If misses exist, `NationalizeClient::fetch($missNames)` (one batched call).
   4. Write each fetched result with `Cache::put($key, $payload, now()->addHours(24))`.
   5. Rebuild results in original input order (duplicates map to the same payload).
3. Resource formats and returns JSON.

### 4.3 Cache design
- Store: memcached (`CACHE_STORE=memcached`, `MEMCACHED_HOST`, etc. in `.env`).
- **Key:** `nationalize:v1:` + `sha1(mb_strtolower(trimmed_name))`. Hashing is required because memcached keys cannot contain whitespace/control characters and are limited to 250 bytes, while names may contain spaces and be arbitrary Unicode. The `v1` prefix lets us invalidate on format change.
- **Value:** the *raw* upstream record for that name (`count` and `country[]` with numeric probabilities; the upstream `name` field is used to map the batched response back to inputs, then dropped before caching — see §7.4), not the formatted response, so display format can change without invalidating the cache.
- **Case handling — Assumption:** the key is case-insensitive (`Mario` and `mario` share an entry) to raise hit rate. The response echoes the *trimmed original-case* name from the request. Confirm Nationalize.io is case-insensitive (§10, Q3).
- Cache also stores "no data" answers (upstream returned an empty `country` array) — this is a valid answer, and re-asking costs quota.
- **Cache unavailable:** if memcached errors, treat as a miss and call upstream (fail open on availability); log a warning without names. See §10, Q6.

### 4.4 Upstream client
- `Http::timeout(5)->retry(2, 200, throw: false)->acceptJson()->get(...)` with `name` passed as an array so Laravel builds `name[]=` params. Timeout/retry values are config-driven (`services.nationalize.timeout`).
- With multiple `name[]`, the upstream returns a JSON array of records `{count, name, country:[{country_id, probability}]}`. Map results back by `name` (upstream echoes the name as sent); do not rely on positional order.
- Any non-2xx, timeout, or malformed payload → `UpstreamUnavailableException` (§5.3). Upstream 429 is surfaced as 503 with `Retry-After` if provided.
- **The API key travels in the query string** (dictated by the upstream). Therefore exception messages, `Http` debug output and any request logging **must redact the URL's `apikey`**. Laravel's `RequestException` messages include the URL; catch and re-throw sanitized exceptions.

### 4.5 Output shaping
- `probability` is a string percentage: `round(p * 100)` + `"%"` (matches intent example `"83%"`).
- Sort by raw numeric probability descending (ties: upstream order, stable) **before** rounding, then take the first 5. `most_probable_country`/`probability` = first element.
- Name with no upstream data: `most_probable_country: null`, `probability: null`, `countries: []`. (Assumption; see §10, Q4.)

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
| 422 | Validation failure (missing/empty/oversized list, non-string or blank item, name too long) | Laravel standard `{"message": "...", "errors": {"names.1": ["..."]}}` |
| 400 | Malformed JSON body | `{"message": "Malformed JSON."}` |
| 415/422 | Non-JSON content type | Laravel default; ensure JSON, not redirect |
| 429 | Local rate limit exceeded (§7) | `{"message": "Too many requests."}` + `Retry-After` |
| 502 | Upstream returned invalid/unexpected data | `{"message": "Name lookup service returned an invalid response."}` |
| 503 | Upstream timeout/unreachable/rate-limited | `{"message": "Name lookup service is temporarily unavailable."}` |
| 500 | Anything else | Generic message; no internals |

Error messages must never include the API key, upstream URL, or the submitted names beyond the field-level validation messages.

## 6. Validation rules

### 6.1 Rules
```php
'names'   => ['required', 'array', 'min:1', 'max:5'],
'names.*' => ['required', 'string', 'max:100'],   // after trim; see Q2 for length
```
Trimming is done in `prepareForValidation()` **only on items that are strings**, so that `123` or `null` still fail `string` rather than being coerced.

### 6.2 Laravel gotchas to test explicitly
- `ConvertEmptyStringsToNull` turns `""` into `null`; `required` then rejects it — correct, but `"   "` must also be rejected (trim first, then check non-empty).
- `names` as an object/associative array (`{"names": {"a": "x"}}`) must be rejected: add an `array:` shape check / `array_is_list`.
- `{"names": "Mario"}` (string, not array) → 422.
- Items that are `true`, `1`, `null`, `[]`, `{}` → 422.
- Unicode: names such as `"Núñez"`, `"李"` are valid; emit as UTF-8 and URL-encode upstream.

## 7. Security considerations
*(No security policy was supplied. The following is generic best practice and should be reconciled with the real policy.)*

1. **Secret handling:** API key only in `.env` (gitignored; commit `.env.example` with an empty `NATIONALIZE_API_KEY=`). Never log it; redact in exceptions (§4.4).
2. **Unauthenticated public proxy to a quota-limited, keyed upstream.** Anyone can spend our Nationalize.io quota. **Recommendation:** per-IP throttle on the route (e.g. `throttle:60,1`), configurable. This does not conflict with "no auth" but is not in the intent; confirm (§10, Q5).
3. **Cache poisoning / key abuse:** hashed keys (§4.3) prevent key injection and length errors; values are only written from validated upstream responses.
4. **Privacy / "no retention":** FR-9 forbids persistence beyond the cache. Names can be personal data. Therefore: do not log request bodies or names; ensure Laravel exception reporting, Telescope/Debugbar and web-server access logs (POST bodies are not logged by default) don't capture them; set `APP_DEBUG=false` in production. Memcached keys are hashed, but the cached value includes upstream's `name` field unless we strip it; **recommendation:** drop `name` from the stored value (it is re-attached from the request on output), so no plaintext name sits in the cache. Decision needed, §10, Q7.
5. **Transport:** terminate TLS in front of the app; upstream calls over HTTPS with certificate verification on (Laravel default; do not disable).
6. **Memcached exposure:** bind to private interface only; no public access (memcached has no auth by default).
7. **Input bounds:** max 5 items and a per-name length cap bound upstream URL size and abuse. Also set a request body size limit at the web server.

## 8. Testing plan
Feature tests use `Http::fake()` and the `array` cache driver (plus one integration test against real memcached in CI if available).

- Happy path single and multiple names; output format exactly matches §5.2 (percent strings, descending order, ≤5 countries).
- Trimming: `"  Mario "` → name `"Mario"`; one upstream param, no whitespace.
- Duplicates: `["Mario","Mario"]` → 2 results, **one** upstream `name[]`.
- Case: `["Mario","mario"]` → one upstream name (per Q3 assumption).
- Cache hit: second request makes **zero** upstream calls (`Http::assertNothingSent`).
- Partial hit: only the missing names are sent.
- TTL: assert entry expires at 24h (`Carbon::setTestNow` / travel).
- Failures not cached: upstream 500 then success → second call goes upstream.
- Validation matrix from §6.2 (0 names, 6 names, `""`, `"  "`, `1`, `null`, nested arrays, wrong shape, too long).
- Upstream errors: 429, 500, timeout, malformed JSON, missing name in response → correct 5xx JSON, no key in body or logs.
- Unknown name (empty `country`) → null fields, empty `countries`.
- Memcached down → falls back to upstream, request succeeds.
- Log-capture test: no name or `apikey` string appears in logs.
- Rate limit returns 429.

## 9. Items that need review against real policies (not verified)
- Brand guidelines: believed N/A (no UI); confirm whether API docs/error wording require brand voice.
- Security policy: whether public unauthenticated endpoints are permitted at all, required rate limits, logging/retention rules, secrets management (is `.env` acceptable in production, or must it be a secrets manager?), dependency/pen-test gates.
- UX standards: error message wording and accessibility of API docs, if any.
- Data-protection/privacy review of sending personal names to a third party (Nationalize.io) — see §10, Q7.

## 10. Areas of concern and open questions

### A. Resolved since r1
The two intent sentences that previously read backwards (duplicate sending; persistence) were corrected by the author and now match the interpretation used in this spec. No open ambiguity remains from them.

### B. Open questions (assumptions made; need an owner)
- **Q1 — Percent rounding:** integer rounding (`83%`) is lossy (e.g. 0.004 → `0%`). Intent's example is integer; adopted as-is. Acceptable?
- **Q2 — Max name length:** not specified. I assumed 100 chars. Needs a decision.
- **Q3 — Case/internal whitespace:** is `Mario` ≡ `mario`? Should internal runs of spaces collapse (`"de  la Cruz"`)? Assumed case-insensitive cache key, no collapsing. Intent says only leading/trailing whitespace is removed.
- **Q4 — Unknown names:** response shape for names with no data is unspecified; I chose null/empty.
- **Q5 — Rate limiting:** not in intent; I recommend it. Needs approval and a limit.
- **Q6 — Memcached outage behavior:** fail open (call upstream) protects availability but can burn quota during an outage; fail closed (503) protects quota. I chose fail open.
- **Q7 — Third-party data sharing:** names submitted by anonymous users are sent to Nationalize.io. The intent states no privacy position. Needs sign-off from whoever owns privacy policy.
- **Q8 — Response `name`:** echo trimmed original-case input (chosen) vs. upstream's canonical value.
- **Q9 — Nationalize.io quota/pricing and batch limit:** upstream supports multiple names per call (intent's example), so 5 is within limits; per-name vs per-request quota accounting was not verifiable here (no network check performed). Verify against current upstream docs before launch.

### C. Genuine conflicts where I cannot fully satisfy both sides
1. **"No authentication" vs. protecting a paid third-party quota and our own security posture.** The intent forbids requiring auth; a public proxy with a secret-backed upstream is inherently abusable. The throttle in §7.2 mitigates but does not eliminate this (IP throttles are evadable; shared NATs may be unfairly limited). Fully resolving this requires either relaxing the no-auth constraint or accepting residual abuse risk.
2. **"No persistence" (now explicit in the intent) vs. operational observability.** Strictly honoring FR-9 means we cannot log names, which limits debugging and abuse investigation. I have chosen to favor no-retention; if security policy requires audit logging, those two requirements collide and a decision is needed (e.g. log only hashes).
3. **"24h cache" vs. "no retention".** The intent carves out the cache explicitly, so this is not a contradiction; flagged only so privacy review sees that cached entries exist for 24h (Q7).
4. **API key in query string (imposed by upstream) vs. secret-hygiene practice.** Keys in URLs leak easily into logs, proxies and error traces. We can mitigate (§4.4) but not eliminate; check whether Nationalize.io also accepts an `Authorization`/header alternative.

## 11. Delivery notes
- Scaffold: `composer create-project laravel/laravel .` (repo currently empty), then update `CLAUDE.md` "Current state" with real build/test/lint commands as its own instructions request.
- Requires PHP `memcached` extension and a memcached instance (add to `docker-compose`/CI).
- Suggested slices: (1) scaffold + route + validation + tests, (2) client + output shaping, (3) cache layer, (4) throttle/redaction/log tests.
