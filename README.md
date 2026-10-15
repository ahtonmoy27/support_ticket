# Support Ticket & SLA Management — Backend

Laravel 13 modular backend for customer support, assignment, business-hour SLAs, conversations, attachments, CSV imports, and operational reporting. PHP 8.3+ is supported by the existing application; PHP 8.4 is the recommended deployment runtime. MySQL 8.4/InnoDB is the production target.

## Implemented

- Session authentication with customer registration, login throttling, logout, CSRF protection, and inactive-account enforcement.
- Customer, agent, and administrator permissions enforced through policies and scoped queries.
- Unique database-derived ticket numbers, validated lifecycle transitions, and transactional audit history.
- Category-aware manual assignment, atomic claiming, and deterministic least-active-workload automatic assignment.
- Versioned SLA rules; UTC timestamps; timezone-aware calendars with multiple shifts, holidays, special openings, and calendar snapshots.
- Business-time warning thresholds, priority recalculation from the original start, and idempotent warnings and breaches.
- Public conversations and internal notes filtered before serialization.
- Private attachments with MIME/extension/size validation and authorized downloads.
- Streaming, queued CSV processing with row journals, progress, invalid-row reports, and external-reference deduplication.
- Durable notification outbox, queued mail, idempotent database notifications, scoped notification reads.
- Paginated search, filters, audit feeds, and fresh database-aggregate dashboards (no response cache).
- Automated feature, calendar, import, notification, and multi-process MySQL concurrency tests.

The working React 19 / TypeScript frontend lives in the companion [support_ticket_front repository](https://github.com/ahtonmoy27/support_ticket_front). Clone both repositories for the complete application. This repository serves the Laravel JSON API; its root welcome page is not the ticket interface. Frontend setup is documented in that repository and in [the integration guide](docs/FRONTEND_IMPLEMENTATION.md).

## Local setup

Run Composer with PHP 8.4 or the installed compatible PHP 8.3:

```sh
composer install
```

For a new installation, copy .env.example to .env and run php artisan key:generate. Preserve an existing application key when upgrading. Create a MySQL 8.4 database with utf8mb4 and a dedicated database user, then set DB_CONNECTION=mysql, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD.

Run the migration/seeding command below against your development database. MySQL tests use a separate support_ticket_sla_test database; never point tests at an application or production database.

Set SUPPORT_ADMIN_EMAIL and SUPPORT_ADMIN_PASSWORD (at least 12 characters) locally before seeding if you need an administrator. No default administrator password is shipped. Seeding creates the account only if that email does not exist, and does not overwrite existing credentials. Remove the bootstrap password after the account is created.

```sh
php artisan migrate --seed
php artisan serve

# In another terminal (processes all background jobs: imports, emails, notifications):
php artisan queue:work --queue=imports,notifications,default

# In a third terminal (monitors SLA timers & escalations every minute):
php artisan schedule:work
```

Database queues work out of the box with `php artisan queue:work`. For high-throughput production setups, you can separate workers by queue:
```sh
php artisan queue:work --queue=imports --timeout=900
php artisan queue:work --queue=notifications,default --timeout=60
```
For production set QUEUE_CONNECTION=redis, CACHE_STORE=redis, SESSION_DRIVER=redis, and configure Redis with a compatible PHP Redis extension. Queue retry_after is 1,200 seconds so the 900-second import timeout cannot cause premature redelivery. Use a shared cache for multi-server scheduler locks. Supervisor/systemd should supervise workers; run php artisan schedule:run every minute from cron. Horizon is not installed; the included implementation uses standard Laravel queue workers.

The default calendar is Sunday–Thursday, 09:00–18:00, Asia/Dhaka. Default targets: low 2,880, medium 1,440, high 480, critical 120 business minutes. Warning is at 80% of business time.

## Authentication and frontend integration

These /api/v1 endpoints deliberately run in the web middleware group; they are session endpoints, not bearer-token endpoints. Send Accept: application/json.

1. GET /api/v1/csrf-token and retain its session cookie.
2. Send the returned csrf_token as X-CSRF-TOKEN on POST/PATCH requests.
3. POST /api/v1/login with email/password, or /api/v1/register with name/email/password/password_confirmation.
4. Send the session cookie on subsequent requests. GET /api/v1/me identifies the actor.
5. POST /api/v1/logout invalidates the session. Obtain a fresh CSRF token for a new login session.

The companion frontend uses a Vite /api proxy during development. Set its API_PROXY_TARGET to this backend, for example http://127.0.0.1:8000. Keep VITE_API_BASE_URL unset for this mode. Production should proxy /api to Laravel on the frontend origin; direct cross-origin requests additionally require explicit credentialed CORS and compatible cookie/session settings.

See [backend endpoint reference](docs/API.md) and [design decisions](docs/ARCHITECTURE.md).

For the complete frontend handoff, screens, typed API integration, permissions, and implementation checklist, see [Frontend Implementation](docs/FRONTEND_IMPLEMENTATION.md).

## CSV imports

Required unique headers:

```csv
customer_email,category,subject,description,priority,source_system,external_ticket_id
customer@example.com,Technical,Cannot sign in,Login fails,critical,legacy,123
```

The last two columns are optional; when present they must both be nonempty. Customers and categories must already exist and be active. Priority must be low, medium, high, or critical. Matching source_system/external_ticket_id pairs are skipped; ordinary identical ticket subjects are not treated as duplicates.

CSV files are private and limited to 50 MB; the default row cap is 100,000. Each row is processed transactionally with a unique import/row journal entry, and invalid rows do not stop valid rows. This implementation processes one row at a time in one import worker, rather than dispatching parallel chunk jobs. Memory remains bounded; high-volume throughput should be benchmarked before changing this strategy.

## Tests

```sh
php artisan test --compact
vendor/bin/pint --format agent
```

The default suite uses in-memory SQLite; MySQL concurrency tests skip on SQLite. To test the production database engine, create the isolated support_ticket_sla_test database and override test database variables. Do not use an application database: RefreshDatabase tests rebuild schemas.

PowerShell example, using local test credentials only:

```powershell
$env:DB_CONNECTION = 'mysql'
$env:DB_DATABASE = 'support_ticket_sla_test'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_USERNAME = 'your_test_user'
$env:DB_PASSWORD = 'your_test_password'
php artisan test --compact
```

The concurrency suite launches real PHP subprocesses against MySQL and verifies a single successful claimant, transactional audit history, unique ticket numbers, and balanced simultaneous assignments. It refuses any MySQL database name other than support_ticket_sla_test.

## Operational limits

- Waiting for customer does not pause the SLA. Resolved and closed tickets stop monitoring. Closed is terminal; reopening is not supported.
- Creating a new SLA policy activates an immutable new version. Existing tickets retain snapshots. Priority changes use the original policy version and start time, and preserve historical breaches.
- One exclusive role is stored on each user. Multi-role RBAC is not exposed. Calendar intervals and exceptions are validated JSON documents rather than separate interval tables.
- Allocation and workload-changing lifecycle operations lock their category row, followed by affected ticket/eligible-agent rows. Unrelated categories can progress independently; shared agents still serialize. Workload counts use one current locking aggregate query. Benchmark contention before introducing workload counters or parallel import chunks.
- Database audit updates/deletes through Eloquent are rejected. Database administrators can still change records directly; production database permissions and retention controls remain operational responsibilities.
- Mail is at-least-once: an interrupted outbox dispatch or external email acknowledgment can cause redelivery. Database notifications deduplicate by event and recipient. Notification workers recheck ticket authorization.
- No malware scanner is integrated; permitted files become available after MIME/extension/size validation. Private S3 can be selected with TICKET_ATTACHMENT_DISK=s3 after installing/configuring the Laravel S3 filesystem adapter.
- MySQL full-text search follows its tokenizer/stopword limitations. SQLite uses LIKE for test/local compatibility. No production performance target is claimed without a representative benchmark.
- Mail defaults to the log driver. Configure your provider for delivery. Redis, SMTP, HTTPS, backups, monitoring, and process supervision must be configured in the deployment environment.

## Containers and CI

[docker-compose.yml](docker-compose.yml) provides PHP 8.4/FPM, Nginx, MySQL 8.4, Redis, workers, and scheduler. Configure DB_PASSWORD, MYSQL_ROOT_PASSWORD, and REDIS_PASSWORD as strong distinct secrets before running:

```sh
docker compose build
docker compose up -d
docker compose exec app php artisan migrate --seed
```

APP_KEY must be set in the local .env. Attachments are stored in the shared private storage volume. Docker configuration is supplied but has not been built/run in this workspace because Docker is unavailable. Provide HTTPS at your ingress and your organization's backup/monitoring services. The included GitHub Actions workflow runs the complete suite against MySQL and checks PHP formatting.

## Recovery and monitoring

The scheduler runs SLA checks, outbox dispatch, stale-import recovery and support monitoring every minute. Keep the scheduler and both queue workers running.

```sh
php artisan tickets:monitor --json
php artisan tickets:recover-imports
php artisan tickets:retry-import 123
```

Replace 123 with an existing failed import ID. Retry resumes the original import and preserves row journals; fix missing files/configuration first. Stale pending/processing imports are requeued after TICKET_IMPORT_STALE_SECONDS (default 1,800 seconds, minimum 1,200). Monitoring checks queue backlog/age, failed imports/jobs, pending outbox and SLA/outbox heartbeats. It emits structured warning logs with cooldown and exits non-zero when unhealthy. The admin-only health endpoint is GET /api/v1/admin/health. An external monitor must independently invoke the command or inspect collected logs to detect a completely stopped scheduler. Historical failed job records remain available for diagnosis; acknowledge resolved records with Laravel queue:forget when appropriate.

The MySQL suite writes storage/framework/testing/support-load.json with local action latency, query counts and rollback/failure counts; CI uploads the report. This is a small concurrency benchmark, not a production HTTP-capacity guarantee.
