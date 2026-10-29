# Postback service

A small Laravel API that receives advertising postbacks, prevents duplicate processing for 24 hours, persists results in MySQL, and forwards JSON to a configured partner.

## Stack and layout

PHP 8.4 in Docker (PHP 8.3+ supported), Laravel 13, MySQL 8.4, Redis 7.4, Docker Compose, PHPUnit 12. Dependencies are pinned by `composer.lock`; no Node build or queue worker is needed.

- `PostbackRequest`: query-only input validation and shared identity rules.
- `NonNegativeAmount`: Laravel validation rule delegating exact decimal validation.
- `EnsureInspectionToken`: optional inspection authentication at the route middleware boundary.
- `DecimalAmount`: string arithmetic normalization without floating point.
- `AcceptPostback`: Redis duplicate fast path plus transactional MySQL identity gate.
- `DeliverPostback`: JSON POST, bounded retries and persisted outcome.
- `PostbackController`: receipt/inspection HTTP contracts.
- `postbacks`: receipt, canonical/original amount, status, attempts, HTTP result and safe error.
- `postback_identities`: one primary-key row per identity; survives cache loss.

## Quick start

Run the commands below from the repository root. Prerequisites: Docker Engine/Desktop running with Docker Compose v2. On Windows use Docker Desktop with Linux containers; run these commands in WSL with Docker integration enabled, or PowerShell with Docker access.

```sh
cp .env.example .env
# PowerShell alternative: Copy-Item .env.example .env
# Edit .env and replace the DB_PASSWORD, DB_ROOT_PASSWORD, REDIS_PASSWORD placeholders.
docker compose config --quiet
docker compose --profile mock up --build -d --wait
docker compose ps
curl -i http://localhost:8000/up
```

After the command finishes successfully, all services should be healthy and GET /up should return HTTP 200. Open:

- [Swagger UI](http://localhost:8000/docs) — browse the API and execute requests.
- [Received events](http://localhost:8000/events) — inspect stored events and delivery results.
- [OpenAPI JSON](http://localhost:8000/openapi.json) — import the API specification into another client.

These URLs assume the default APP_PORT=8000; adjust them if you changed it. Docker installs the PHP dependencies during the image build; no local PHP, Composer or Node installation is required.

The entrypoint generates a random Laravel application key if `APP_KEY` is empty, persists it in the app storage volume, runs migrations, then starts four PHP development-server workers. MySQL and Redis must pass healthchecks before app startup. MySQL has an explicit 60-second graceful shutdown window; Redis has 30 seconds. The mock is optional; --wait ensures its healthcheck has passed before the quick-start command returns.

```sh
docker compose logs --tail=100 app
docker compose exec app php artisan migrate:status
docker compose exec app php artisan route:list
```

MySQL, Redis and app storage have named volumes. `docker compose down` retains data. `docker compose down -v` **deletes local project data**. Credentials are initialized only when the MySQL volume is first created; changing .env passwords does not update an existing database user.

Docker uses the PHP development server for local development; use a managed PHP runtime/reverse proxy with HTTPS for Internet deployment.

## Configuration

| Variable | Meaning |
| --- | --- |
| `PARTNER_API_URL` | Trusted operator-configured HTTP(S) POST destination |
| `PARTNER_CONNECT_TIMEOUT` | Connection timeout, default 2 seconds, clamped to 0.1–10 |
| `PARTNER_TIMEOUT` | Per-attempt overall timeout, default 5 seconds, clamped to 0.1–30 |
| `PARTNER_RETRY_DELAY_MS` | Fixed delay between transient failures, default example 100ms, clamped to 0–5000 |
| `DB_*`, `REDIS_*` | Database/cache connection settings; internal hosts set by Compose |
| `INSPECTION_TOKEN` | Optional bearer token protecting `GET /events`; set before any nonlocal deployment |
| `APP_PORT` | Host HTTP port, default 8000; bound to 127.0.0.1 |
| `APP_KEY` | Optional explicit Laravel key; otherwise generated and persisted |
| `DEDUP_STORE` | Defaults to redis; array is used only by isolated tests |

`.env`, logs, generated keys and vendor dependencies are excluded from Git/Docker contexts. Compose forces `APP_DEBUG=false`. MySQL and Redis have no published host ports. Redis socket connection/read timeouts are one second; failures fall back to MySQL.

With the mock profile, the example destination is `http://partner-mock:8080/events`. The mock accepts JSON POST and returns `{"status":"ok"}`; inspect `docker compose logs partner-mock` for its local HTTP access logs. To use the real partner, change `PARTNER_API_URL` then run `docker compose up -d --force-recreate app` without the mock profile. Do not put secrets in URLs; use a partner-specific authentication extension if needed. HTTP redirects are disabled. Internal/mock URLs are deliberately permitted because configuration is trusted.

## Swagger UI

Open [http://localhost:8000/docs](http://localhost:8000/docs) for interactive API documentation. The OpenAPI 3.0 specification is available at [/openapi.json](http://localhost:8000/openapi.json), with receipt examples, validation errors, delivery statuses, pagination and the health endpoint.

Use **Try it out → Execute** on GET /postback to submit a real event. Repeat the same identity (49.90 and 49.9 are equivalent) to inspect the duplicate response; change click_id to create a new event. Use GET /events to inspect persisted delivery status and attempts. If INSPECTION_TOKEN is configured, click **Authorize** and enter the token without the Bearer prefix. Tokens are not persisted across page reloads.

Swagger UI 5.34.0 assets are pinned and served locally, so no CDN or separate Node build is needed. Remote specification validation is disabled. Documentation uses the current host, including a custom APP_PORT. Update resources/openapi.json when API behavior changes. The documentation endpoints contain no event data or secrets; /events still enforces its configured token. Source/license details are in public/vendor/swagger-ui/README.md.

## Requests and exact responses

First request:

```sh
curl -i 'http://localhost:8000/postback?click_id=clk_123&event=purchase&amount=49.90'
```

HTTP 200:
```json
{"status":"accepted","message":"Event received"}
```

Equivalent numeric amount within 24 hours:

```sh
curl -i 'http://localhost:8000/postback?click_id=clk_123&event=purchase&amount=49.9'
```

HTTP 200:
```json
{"status":"duplicate","message":"Event already received"}
```

Invalid request:

```sh
curl -i 'http://localhost:8000/postback?click_id=clk_123&event=purchase&amount=-1'
curl -i 'http://localhost:8000/postback?event=purchase'
```

HTTP 422:
```json
{"status":"error","message":"Invalid parameters"}
```

`click_id` and `event` must be nonblank valid UTF-8 strings of at most 255 characters without control bytes. Identity strings are preserved; case and surrounding whitespace on nonblank strings distinguish events. Arrays are invalid. Amounts support decimal/scientific notation (including `.5`, `49.90`, `4.99e1`), optional plus, and negative zero. Nonzero negatives, NaN/INF and unsupported precision return 422. Input length is bounded at 128 characters; the canonical value supports up to 18 integer and 8 significant fractional places. Excess trailing fractional zeroes are normalized away. These explicit bounds prevent overflow/resource abuse. Encode a literal plus as `%2B` in a query.

The partner receives a JSON numeric amount emitted directly from the canonical decimal:
```json
{"click_id":"clk_123","event":"purchase","amount":49.9}
```
Inspection represents `amount` as a string so clients retain exact precision.

Only URL query parameters are used; a GET request body cannot supply or override them. `HEAD /postback` returns HTTP 405 without storing or forwarding an event.

Acceptance means the event was stored; partner failure does not change the accepted response. Delivery runs synchronously before the response. Infrastructure failure before durable acceptance returns HTTP 503 `{"status":"error","message":"Service unavailable"}`. A duplicate during an existing delivery returns duplicate and does not start another send.

## Inspecting events

```sh
curl 'http://localhost:8000/events?page=1'
# When INSPECTION_TOKEN is set:
curl -H 'Authorization: Bearer your-token' 'http://localhost:8000/events?page=1'
```

Newest first, 50 rows per page, Laravel paginator with `data`, `current_page`, `last_page`, `total` and navigation links. Fields include ID, original identity, `original_amount`, canonical `amount`, `received_at`, `status`, `attempts`, `last_http_status`, and `last_error`.

Statuses: `pending` before delivery; `sending` during an attempt; `succeeded` after any 2xx; `failed` after an unsuccessful attempt/configuration failure. During a retry delay, the last unsuccessful attempt is visible as failed; another attempt may still follow. `last_error` contains a safe code such as `http_503`, `network_error`, or `invalid_partner_configuration`. Response bodies and raw exception messages are not stored.

## Deduplication and failure boundaries

The identity is SHA-256 of the JSON tuple `[click_id,event,canonical_amount]`, avoiding separator ambiguity. `49.9`, `49.90`, `049.900` and `4.99e1` have the same identity.

Redis is checked first. On a cache miss/outage, a MySQL transaction inserts the identity row if absent, locks that row with `SELECT FOR UPDATE`, and compares the last acceptance time. It creates a postback only if no receipt exists in the previous 24 hours. Concurrent equal requests serialize on the same primary key. Deadlocks retry the acceptance transaction up to three times; this is separate from delivery attempts.

Only after commit is the Redis marker written, with expiration derived from the **original** receipt time. Duplicates never extend expiry. A failed insert rolls back both event and identity changes and writes no marker. A crash after commit/before caching is recovered through MySQL on the next duplicate. Redis restart/eviction/outage therefore cannot create another accepted row. Both MySQL identity and Redis state must refer to the same database deployment; do not restore MySQL independently while retaining unrelated Redis markers.

MySQL unavailability prevents new acceptance. An existing Redis marker may still return duplicate during a database outage. Redis errors are logged and acceptance continues through the database. Identity rows are retained indefinitely. Configure a retention policy when operating the service long-term.

## Delivery and retries

Delivery happens outside the transaction against the already persisted row. Attempt intent/count is stored before each HTTP call. All HTTP 200–299 succeed. Only connection failures/timeouts, 429, and 500–599 retry; other statuses, including other 4xx and redirects, stop immediately. A truncated response body can raise a transport exception even after HTTP error headers arrive; the carried 429/5xx status is still retried, while other 4xx statuses stop immediately. There are at most **three total attempts**, with two bounded delays. Defaults bound outbound time to approximately 15.2 seconds plus database/cache overhead.

A stable `Idempotency-Key` equal to the postback ID accompanies all attempts. It helps only if the partner implements idempotency; no such support is assumed. A timeout can mean the partner processed a request but its response was lost.

There is no queue or worker to manage. Process termination or persistence failure after acceptance can leave pending/sending (or a previous failed attempt) visible. Duplicate requests intentionally do not resume delivery. There is no automatic crash recovery/outbox, and **exactly-once external delivery is not guaranteed**. Reconcile interrupted/ambiguous rows with the partner before any manual resend; starting a new identity to bypass deduplication is unsafe.

## Automated tests

```sh
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test
docker compose exec app composer validate --strict
docker compose exec app composer audit
```

The default suite uses SQLite in memory, an isolated array cache and Laravel HTTP fakes. It also starts temporary loopback TCP servers to verify actual truncated HTTP responses and timeouts; it never contacts the configured external partner. These TCP tests require pcntl, which is installed in the Docker image. It covers validation contracts, exact decimal handling, fixed-window behavior, cache recovery, transactional failure, retry classification and inspection. Four MySQL/Redis infrastructure tests are opt-in and skip by default.

For MySQL/Redis integration, create a separate database/user accessible from the app (never use your application database). The name must end with `_test`. Tests migrate this dedicated database, use unique test identities, and leave uniquely identified test rows for inspection; Redis test markers expire automatically. No HTTP partner network access is needed in these tests.

The app DB user needs permissions on that dedicated test database. For the default example username, open the root SQL console (the password is read inside the container):

```sh
docker compose exec mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot'
```

Run these statements only for the dedicated test database (replace the username if changed):

```sql
CREATE DATABASE IF NOT EXISTS postbacks_test;
GRANT ALL PRIVILEGES ON postbacks_test.* TO 'postbacks'@'%';
```

Exit the SQL console, then run the opt-in integration tests:

```sh
docker compose exec -e RUN_INFRA_TESTS=1 -e TEST_DB_DATABASE=postbacks_test \
  app php artisan test --testsuite=Integration
```

Six concurrent forked HTTP-kernel requests verify one accepted response, five duplicates, one row and one delivery attempt. Real Redis checks verify TTL and cache-loss reconciliation. PHP pcntl is installed in the image.

Without Docker, install PHP 8.3+, Composer, mbstring, XML/DOM, curl, pdo_sqlite, pdo_mysql and phpredis. Run `composer install`, configure MySQL/Redis in .env, `php artisan key:generate`, `php artisan migrate` and `php artisan serve`. Use `php artisan test` for the isolated suite.

## Troubleshooting: “events are not reaching the partner”

Follow this sequence; acceptance and successful delivery are separate facts.

1. **Confirm inbound reception.** Use the caller's actual timestamp and tuple, HTTP access logs and response. In local Compose, PHP server access logs appear in `docker compose logs app`. GET query strings can contain identifiers; restrict access to infrastructure logs and apply retention/redaction in a production proxy. Check the caller reached the correct host/port/path and that `/up` answers. An absent receipt may indicate routing/network failure before the app.
2. **Check validation.** HTTP 422 means no row and no outbound request. Compare the exact response, presence of all three fields, empty/array values, UTF-8 and supported numeric precision. Reproduce with a unique diagnostic identity only when sending a new event is intended.
3. **Inspect persistence.** Read `GET /events` (with bearer token if configured), locate the exact tuple/time and inspect `received_at`. Run `php artisan migrate:status`. For HTTP 503, check DB health/credentials/permissions and the `postback_persistence_failed` log. Never assume 200 accepted means partner success.
4. **Check deduplication.** A duplicate response deliberately produces no send. Compare canonical amounts and first receipt time. Inspect Redis safely with `docker compose exec redis sh -c 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli -n 1 --scan --pattern "*postback:*"'`; use `TTL` on the matching key. Do not delete markers to force delivery: MySQL also enforces the window. `dedup_cache_unavailable` indicates fallback; verify DB identity state if Redis data was lost.
5. **Read processing status/count.** `succeeded` plus 2xx indicates the partner acknowledged HTTP receipt, not necessarily its downstream business processing. `failed` plus HTTP status identifies a response failure. `network_error` means no usable response. `pending`/stale `sending` may indicate a crash; no separate queue worker exists to restart it. Check `docker compose ps`, restart history and app health.
6. **Examine sanitized application logs.** `docker compose logs --since=30m app`; correlate `partner_delivery_failed`, `postback_delivery_interrupted`, or `partner_configuration_invalid` by `postback_id`, attempt and HTTP status. Raw partner bodies/URLs are intentionally omitted. Infrastructure access logs may contain inbound query identifiers.
7. **Verify effective destination.** Check the configured environment in your deployment without printing secrets. After changes recreate the app; clear/rebuild Laravel configuration cache if you enabled it. Confirm scheme/path/port and whether the mock profile or real endpoint is intended. `localhost` inside the app container is the app container, not the host or partner.
8. **Check DNS/network from the app container.** Confirm the configured hostname resolves, Compose services share networking, the mock is healthy, and firewalls/proxy/TLS certificates allow outbound access. A safe probe to the mock: `docker compose exec app php -r 'var_dump(file_get_contents("http://partner-mock:8080/health"));'`. Avoid replaying real event POSTs while probing.
9. **Review HTTP results and timeouts.** 400/422 usually require payload/partner-contract correction and are not retried. 401/403 may indicate partner authentication/access policy. 429/5xx trigger retries. Redirects fail because following them could send payloads elsewhere. Inspect TLS/DNS/connectivity for network errors and compare partner latency with the configured deadlines.
10. **Confirm retry counts.** At most three attempts; 503→429→204 records succeeded with attempts=3. Exhaustion records failed with attempts=3. Duplicate requests never add attempts. Changing partner configuration does not automatically replay existing failed rows.
11. **Locate the failure boundary.** No row/422/503 means rejection before outbound transmission. attempts=0 means delivery was not started or configuration invalid. attempts>0 records intent to send but cannot prove bytes reached the partner. A 2xx proves acknowledgement; timeout/process loss can be ambiguous. Ask the partner to correlate click/event/time and Idempotency-Key before agreeing on any manual recovery.

## Operational limits

The GET ingestion endpoint is unauthenticated. Before Internet exposure add partner signatures/allowlists, ingress rate limiting, HTTPS and protected inspection; prevent proxies from caching the receipt response. The API sets no-store headers. There is no broad authentication platform, exactly-once guarantee, automated replay, archival cleanup, or production PHP server in this implementation.
