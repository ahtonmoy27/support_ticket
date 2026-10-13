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
- Paginated search, filters, audit feeds, and cached database-aggregate dashboards.
- Automated feature, calendar, import, notification, and multi-process MySQL concurrency tests.

The frontend is intentionally outside this implementation. The existing welcome page remains. All backend endpoints return JSON and use the existing Laravel web/session middleware so a future React/Inertia frontend can reuse the same domain actions.

## Local setup

Run Composer with PHP 8.4 or the installed compatible PHP 8.3:

```sh
composer install
```

Use your existing .env or copy .env.example for a new installation. Set APP_KEY with php artisan key:generate if absent. Create a MySQL 8.4 database with utf8mb4 and a dedicated database user, then set DB_CONNECTION=mysql, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD.

The current workspace .env is preserved. Migrations and the standard categories/SLA rules have been applied to its configured database. MySQL verification uses a separate support_ticket_sla_test database and does not switch your application database.

Set SUPPORT_ADMIN_EMAIL and SUPPORT_ADMIN_PASSWORD (at least 12 characters) locally before seeding if you need an administrator. No default administrator password is shipped. Seeding creates the account only if that email does not exist, and does not overwrite existing credentials. Remove the bootstrap password after the account is created.

```sh
php artisan migrate --seed
php artisan serve
php artisan queue:work --queue=notifications,default --tries=3 --timeout=60
php artisan queue:work --queue=imports --tries=3 --timeout=900
php artisan schedule:work
```

Database queues work out of the box. For production set QUEUE_CONNECTION=redis, CACHE_STORE=redis, SESSION_DRIVER=redis, and configure Redis with a compatible PHP Redis extension. Queue retry_after is 1,200 seconds so the 900-second import timeout cannot cause premature redelivery. Use a shared cache for multi-server scheduler locks. Supervisor/systemd should supervise independent notification and import workers; run php artisan schedule:run every minute from cron. Horizon is not installed; the included implementation uses standard Laravel queue workers.

The default calendar is Sunday–Thursday, 09:00–18:00, Asia/Dhaka. Default targets: low 2,880, medium 1,440, high 480, critical 120 business minutes. Warning is at 80% of business time.

## Authentication and frontend integration

These /api/v1 endpoints deliberately run in the web middleware group; they are session endpoints, not bearer-token endpoints. Send Accept: application/json.

1. GET /api/v1/csrf-token and retain its session cookie.
2. Send the returned csrf_token as X-CSRF-TOKEN on POST/PATCH requests.
3. POST /api/v1/login with email/password, or /api/v1/register with name/email/password/password_confirmation.
4. Send the session cookie on subsequent requests. GET /api/v1/me identifies the actor.
5. POST /api/v1/logout invalidates the session. Obtain a fresh CSRF token for a new login session.

Use same-origin deployment for the future frontend. A separate cross-origin SPA would require additional deliberate CORS/session configuration.

See [backend endpoint reference](docs/API.md) and [design decisions](docs/ARCHITECTURE.md).

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
- Assignment uses a shared database lock to serialize allocation and active-workload-changing lifecycle operations. This favors correctness at assessment scale; replace it with ordered per-agent workload locks/counters if measured throughput requires it.
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
