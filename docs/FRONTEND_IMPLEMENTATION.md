# Frontend integration

The implemented frontend is maintained in [support_ticket_front](https://github.com/ahtonmoy27/support_ticket_front). It uses React 19, TypeScript, React Router 7, Vite 8 and Tailwind 4. It does not use Inertia. Clone both repositories; the Laravel welcome page is not the application UI.

## Local setup

1. Follow the backend README: Composer dependencies, environment/key, MySQL database, migration/seeding, administrator bootstrap, workers and scheduler.
2. In the frontend repository, use a Vite-8-compatible Node.js runtime (Node 22.12+ or a compatible newer release), run npm ci, copy .env.example to .env and set API_PROXY_TARGET to the locally running Laravel server.
3. Leave VITE_API_BASE_URL unset to use the same-origin /api proxy. Run npm run dev. Vite uses port 5173, and preview uses 4173.
4. Register a customer through the UI. Bootstrap an administrator with backend SUPPORT_ADMIN_EMAIL/SUPPORT_ADMIN_PASSWORD and the seeder; create agents and category mappings through the admin UI. No default login credentials are supplied.

## Contract

- Session cookies and explicit CSRF headers authenticate mutations. Login clears the guest CSRF cache; the next mutation obtains the authenticated token. Expired-token errors preserve input and never automatically replay a mutation.
- Use /api/v1/me for the current actor. Authorization remains server-side; frontend permission checks only control presentation.
- Lists normalize Laravel resource pagination (data/meta/links). Categories are an unpaginated data array. API pagination URLs are not UI navigation URLs.
- Tickets expose created_by separately from customer_id. List/detail include creator ID/name for display, without private account fields.
- Ticket statuses and legal transitions come from TicketStatus and TicketPolicy. Resolved/closed tickets cannot receive replies/uploads; closed tickets cannot reopen.
- Internal notes and internal attachments never appear in customer responses. Downloads use the authorized attachment endpoint, not a public storage URL.
- Ticket/message creation and attachment uploads are separate operations. Failed uploads retain the saved ticket/message ID and failed files so retry does not create duplicate records.
- Search/filter changes update related URL parameters atomically. Polling stops on terminal authorization/not-found errors and respects Retry-After for throttled requests.
- Imports are streamed asynchronously by the backend. Progress polling stops on completion/failure; failed-row CSV downloads remain authorized. Failed import resume uses the operator CLI, not a nonexistent UI retry API.
- Notifications are paginated; reading them immediately refreshes unread indicators. Queued email requires an actual configured mail provider and running notification worker.

## Screens

Login/registration, scoped dashboard, ticket list/search/filter, ticket creation/editing, detail/conversation/internal notes, uploads/downloads, activity timeline, notifications, agent/category administration, SLA policy versions/business calendars, and CSV import list/detail/progress/error reports are implemented in the frontend repository.

## Verification and deployment

Run npm test, npm run lint, npm run typecheck and npm run build in the frontend. Run php artisan test --compact in the backend; use the isolated MySQL database for subprocess/concurrency/crash coverage. Tests do not establish real SMTP delivery or production HTTP capacity.

Host the frontend build with SPA navigation fallback. Route /api to Laravel before applying the fallback, preserve session cookies and avoid caching authenticated API responses. Vite's development proxy is not included in static production assets. The frontend includes a Vercel rewrite configuration; verify its backend destination and cookie/HTTPS settings for your environment. For direct cross-origin access, configure credentialed CORS/session cookies deliberately. Never expose secrets through VITE_* variables.
