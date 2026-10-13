# Frontend Implementation Handoff — Support Ticket & SLA Management

This is the implementation brief for a frontend developer or coding agent working in this repository. It describes the existing backend contract, the required interface, integration code, missing API capabilities, and completion checks.

**Scope:** build the complete customer, support-agent, and administrator interface, integrated with the real backend. This document does not claim that frontend code already exists.

**Source of truth:** routes/backend.php, controllers, Form Requests, TicketResource, TicketPolicy, and domain actions. If code changes later, inspect these files and update this document. All example IDs and response values below are illustrative, not seeded accounts or production records.

## Contents

1. [Architecture and setup](#1-architecture-and-setup)
2. [Screens and navigation](#2-screens-and-navigation)
3. [UI design and shared components](#3-ui-design-and-shared-components)
4. [Authentication and CSRF](#4-authentication-and-csrf)
5. [TypeScript API client](#5-typescript-api-client)
6. [Types and response envelopes](#6-types-and-response-envelopes)
7. [Complete endpoint map](#7-complete-endpoint-map)
8. [Ticket lists and filters](#8-ticket-lists-and-filters)
9. [Create and edit tickets](#9-create-and-edit-tickets)
10. [Ticket details and lifecycle](#10-ticket-details-and-lifecycle)
11. [Assignment and permissions](#11-assignment-and-permissions)
12. [Conversations and internal notes](#12-conversations-and-internal-notes)
13. [Attachments and downloads](#13-attachments-and-downloads)
14. [SLA display](#14-sla-display)
15. [Dashboard](#15-dashboard)
16. [Notifications](#16-notifications)
17. [Administrator: categories and agents](#17-administrator-categories-and-agents)
18. [Administrator: SLA policies](#18-administrator-sla-policies)
19. [Administrator: CSV imports](#19-administrator-csv-imports)
20. [Audit timeline](#20-audit-timeline)
21. [Errors, loading, and refresh](#21-errors-loading-and-refresh)
22. [Backend limitations and fallbacks](#22-backend-limitations-and-fallbacks)
23. [Implementation sequence](#23-implementation-sequence)
24. [Acceptance checklist](#24-acceptance-checklist)
25. [Copy-ready implementation prompt](#25-copy-ready-implementation-prompt)

## 1. Architecture and setup

### Existing repository

- Laravel 13 backend; installed Composer constraint is PHP 8.3+, with PHP 8.4 as the target runtime.
- Laravel Vite plugin, Vite 8, Tailwind CSS 4.
- resources/js/app.js is currently an empty entry point.
- React, TypeScript, Inertia, and shadcn/ui are not installed in the current package.json.
- JSON endpoints use the Laravel **web middleware group**, session authentication, and CSRF protection.
- There is no token issuing endpoint and no bearer-token authentication contract.
- Existing business logic is under app/Domain. Reuse it; frontend components never decide authorization or SLA deadlines.
- The welcome page exists; the application frontend still needs to be built.

### Frontend implementation target

Follow the original project stack: **React 19 + TypeScript + Inertia 3 + Tailwind CSS 4**, with accessible shadcn/ui components where useful. Keep everything in the existing Laravel repository.

Use Inertia for **page navigation** and the existing JSON endpoints for **API data and mutations**. This reconciles the requested frontend stack with the backend that is already present:

1. Add presentation-only Laravel GET routes and Inertia page responses for the UI paths below.
2. Use a React root layout, authentication provider, and shared JSON API client.
3. Call /api/v1 endpoints with ordinary fetch from the client.
4. Do not send Inertia mutation requests to endpoints that return ordinary JSON. An Inertia visit expects an Inertia response, not this API's JSON envelope.
5. Do not replace the existing API, install a second Laravel project, or create a separately hosted React SPA.
6. Do not overwrite backend actions, migrations, or policies merely to add UI rendering.

The UI routes below are **proposed routes to implement**, not existing API endpoints. Preserve /api/v1/* and /up when adding page routes. Avoid a broad catch-all that swallows backend routes. A direct browser reload on every UI path must render its page.

Use this proposed frontend structure:

~~~text
resources/js/
  app.tsx
  types/
    api.ts
    auth.ts
    tickets.ts
    admin.ts
  lib/
    api-client.ts
    pagination.ts
    dates.ts
    permissions.ts
    validation-errors.ts
  api/
    auth.ts
    tickets.ts
    conversations.ts
    attachments.ts
    dashboard.ts
    notifications.ts
    admin.ts
    imports.ts
  providers/
    auth-provider.tsx
  hooks/
    use-ticket-filters.ts
    use-import-polling.ts
  layouts/
    guest-layout.tsx
    app-layout.tsx
  components/
    ui/
    tickets/
    dashboard/
    admin/
    feedback/
  pages/
    auth/login.tsx
    auth/register.tsx
    dashboard.tsx
    tickets/index.tsx
    tickets/create.tsx
    tickets/show.tsx
    tickets/edit.tsx
    notifications/index.tsx
    admin/agents/index.tsx
    admin/categories/index.tsx
    admin/sla-policies/index.tsx
    admin/imports/index.tsx
    admin/imports/show.tsx
    errors/forbidden.tsx
    errors/not-found.tsx
~~~

Add dependency versions compatible with the existing packages, TypeScript configuration, React Vite plugin, Inertia middleware/root template, and updated Vite entry points during frontend implementation. Check package engine requirements locally. The commands below describe the final workflow; they do not mean those dependencies are installed today.

### Runtime setup

~~~sh
composer install
npm install
php artisan migrate --seed
npm run dev
php artisan serve
~~~

Visit the **Laravel application origin**, not the Vite asset server. Use exactly the same hostname throughout login, page visits, and API requests. Mixing localhost, 127.0.0.1, and a Laragon hostname breaks cookie continuity.

For background features, run separate processes:

~~~sh
php artisan queue:work --queue=notifications,default --tries=3 --timeout=60
php artisan queue:work --queue=imports --tries=3 --timeout=900
php artisan schedule:work
~~~

Use README.md for database, Redis, mail, and administrator bootstrap configuration. Never expose .env secrets through Vite variables. Only public frontend configuration may use VITE_*.

## 2. Screens and navigation

| Proposed UI path | Roles | Screen and main behavior |
|---|---|---|
| /login | Guest | Email/password, inline errors, pending state |
| /register | Guest | Customer registration and password confirmation |
| /dashboard | All authenticated roles | Scoped metrics and ticket shortcuts |
| /tickets | All authenticated roles | Search, filters, pagination, role-specific views |
| /tickets/create | All active roles | Create ticket; optional files uploaded afterward |
| /tickets/:id | Authorized actors | Ticket summary, conversation, attachments, timeline |
| /tickets/:id/edit | Authorized actors | Edit allowed fields of an active ticket |
| /notifications | All authenticated roles | Notifications, read status, ticket navigation |
| /admin/agents | Admin | Agent list, create, edit, category mappings, availability |
| /admin/categories | Admin | Category list, create, edit, deactivate/reactivate |
| /admin/sla-policies | Admin | Version list and new-policy/calendar editor |
| /admin/imports | Admin | Upload CSV and view import history |
| /admin/imports/:id | Admin | Progress, summary, failed-row CSV |
| Forbidden/not-found views | As appropriate | Recoverable error pages without private data |

Navigation:

- Customer: Dashboard, My Tickets, Create Ticket, Notifications.
- Agent: Dashboard, Assigned Tickets, Available Queue, Create Ticket, Notifications.
- Admin: Dashboard, All Tickets, Create Ticket, Agents, Categories, SLA Policies, Imports, Notifications.
- All authenticated roles: account name/role display and logout.
- Admin-only navigation should also have a client route guard; the backend remains the authorization authority.

Assigned Tickets uses assigned_agent_id=currentUser.id. Available Queue uses unassigned=1. Both queries are still scoped by the backend.

## 3. UI design and shared components

Build a clean support workspace: neutral surfaces, readable typography, compact tables, restrained status colors, and a responsive sidebar. Prioritize usable workflows over ornamental charts.

Required shared components:

- AppSidebar, Topbar, UserMenu, MobileNavigation.
- PageHeader, SearchInput, FilterBar, Pagination, EmptyState, ErrorState, Skeleton.
- StatusBadge, PriorityBadge, SlaBadge, TicketNumberLink.
- TicketForm, TicketTable/TicketCards, TicketActionMenu, AssignmentDialog.
- ConversationThread, ReplyComposer, AttachmentPicker, AttachmentList.
- ActivityTimeline, NotificationList, ImportProgress.
- AgentForm, CategoryForm, BusinessHoursEditor, HolidayEditor, SlaRuleEditor.
- ConfirmDialog for resolve/close, reassignment, agent deactivation, and SLA activation.
- FormField with label, error association, help text, and disabled/pending states.

Accessibility and responsive requirements:

- Keyboard-operable controls, visible focus, meaningful labels, dialog focus management.
- Never encode priority/status solely through color.
- 360px mobile layouts must remain usable; tables become cards or intentionally scroll.
- Desktop ticket details may have a content column plus metadata/action sidebar.
- Render description/message/audit content as escaped text. Preserve whitespace with CSS. Do not use raw HTML insertion for user content.
- Show success feedback only after confirmed API success.
- Preserve unsent drafts after validation, conflict, or temporary network failures.

## 4. Authentication and CSRF

### Session lifecycle

1. GET /api/v1/csrf-token with cookies enabled.
2. Keep csrf_token in memory.
3. POST login or register with X-CSRF-TOKEN and the same session cookie.
4. After successful login/register, **refresh the CSRF token**: Laravel regenerates the session and token.
5. GET /api/v1/me to restore the actor on application bootstrap or page reload.
6. POST /api/v1/logout; clear auth state and cached private data, then clear/refresh the token before another guest mutation.

Authentication credentials live in HttpOnly session cookies. Do not create a fake JWT, store passwords, or copy the session cookie into localStorage.

When adding Inertia pages, authenticated page routes can use Laravel auth/active-account middleware. Guest page routes should redirect already authenticated actors to the dashboard. Bootstrap the client from /me or explicitly authorized server props; never trust client storage for the role.

### Requests

~~~json
{
  "email": "customer@example.com",
  "password": "user-entered-password"
}
~~~

Registration:

~~~json
{
  "name": "Customer Name",
  "email": "customer@example.com",
  "password": "user-entered-password",
  "password_confirmation": "user-entered-password"
}
~~~

Registration creates a customer only. Do not include role. Name/email maximum: 255 characters. Registration password minimum: 12 characters. Login accepts existing credentials; do not incorrectly enforce the registration password-length rule on login.

Login returns 200; registration returns 201. Both use:

~~~json
{
  "data": {
    "id": 7,
    "name": "Customer Name",
    "email": "customer@example.com",
    "role": "customer",
    "is_active": true,
    "is_available": true
  }
}
~~~

Other user timestamps/fields may be included; password and remember_token are hidden. /me has the same data envelope. Logout returns {"message":"Logged out."}.

Do not treat /me failures uniformly: 401 means guest/expired session; 403 can mean an inactive account; network/500 errors need a retry/error state rather than silently declaring the user a guest.

## 5. TypeScript API client

This reference client uses same-origin fetch, explicit CSRF, JSON and multipart payloads, abort signals, and structured error responses. Adapt it to the chosen frontend state layer.

It intentionally does **not** automatically replay mutations after timeout, 419, or network failure. An unknown result could already have committed. For 419, refresh the token and let the user resubmit preserved form data. Do not apply this client to arbitrary external URLs.

~~~ts
export type FieldErrors = Record<string, string[]>;

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly errors: FieldErrors = {},
    public readonly retryAfter: string | null = null,
  ) {
    super(message);
    this.name = "ApiError";
  }
}

const API_BASE = "/api/v1";
let csrfToken: string | null = null;
let csrfRequest: Promise<string> | null = null;

export function clearCsrfToken(): void {
  csrfToken = null;
}

async function responseError(response: Response): Promise<ApiError> {
  const payload = await response.json().catch(() => null) as {
    message?: string;
    errors?: FieldErrors;
  } | null;
  return new ApiError(
    response.status,
    payload?.message ?? "Request failed. Please try again.",
    payload?.errors ?? {},
    response.headers.get("Retry-After"),
  );
}

export async function ensureCsrfToken(): Promise<string> {
  if (csrfToken) return csrfToken;
  if (csrfRequest) return csrfRequest;

  csrfRequest = (async () => {
    const response = await fetch(API_BASE + "/csrf-token", {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      cache: "no-store",
    });
    if (!response.ok) throw await responseError(response);
    const payload = await response.json() as { csrf_token?: string };
    if (!payload.csrf_token) throw new Error("CSRF response is invalid.");
    csrfToken = payload.csrf_token;
    return csrfToken;
  })();

  try {
    return await csrfRequest;
  } finally {
    csrfRequest = null;
  }
}

export type RequestOptions = Omit<RequestInit, "body"> & {
  body?: unknown;
};

export async function apiRequest<T>(
  path: string,
  options: RequestOptions = {},
): Promise<T> {
  if (!path.startsWith("/") || path.startsWith("//")) {
    throw new Error("Use an API-relative path.");
  }

  const { body, ...init } = options;
  const method = (init.method ?? "GET").toUpperCase();
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");

  if (!["GET", "HEAD", "OPTIONS"].includes(method)) {
    headers.set("X-CSRF-TOKEN", await ensureCsrfToken());
  }

  const multipart = body instanceof FormData;
  if (body !== undefined && !multipart) {
    headers.set("Content-Type", "application/json");
  }
  if (multipart) {
    headers.delete("Content-Type"); // Browser supplies multipart boundary.
  }

  const response = await fetch(API_BASE + path, {
    ...init,
    method,
    headers,
    credentials: "same-origin",
    cache: "no-store",
    body: body === undefined ? undefined
      : multipart ? body
      : JSON.stringify(body),
  });

  if (!response.ok) {
    if (response.status === 419) clearCsrfToken();
    throw await responseError(response);
  }
  if (!response.headers.get("Content-Type")?.includes("application/json")) {
    throw new ApiError(response.status, "Expected JSON; received a page or redirect.");
  }
  return await response.json() as T;
}

export async function refreshCsrfToken(): Promise<void> {
  clearCsrfToken();
  await ensureCsrfToken();
}
~~~

Auth usage:

~~~ts
const result = await apiRequest<{ data: User }>("/login", {
  method: "POST",
  body: { email, password },
});
setCurrentUser(result.data);
await refreshCsrfToken();
~~~

For register, use the registration payload and the same token-refresh sequence. On logout, clear auth/private caches immediately after success; clear and reinitialize CSRF before guest mutations.

Use AbortController for search/list/detail requests. A cancelled request should not show an error toast. Guard response ordering so an old search result cannot overwrite a newer filter state.

Use dedicated blob handling for downloads rather than calling apiRequest<T>; see section 13.

## 6. Types and response envelopes

### Shared types

~~~ts
export type Role = "admin" | "agent" | "customer";
export type Priority = "low" | "medium" | "high" | "critical";
export type TicketStatus =
  | "open" | "in_progress" | "waiting_for_customer" | "resolved" | "closed";
export type SlaState = "within" | "warning" | "breached" | "met";
export type Visibility = "public" | "internal";
export type Timestamp = string;

export interface NamedRecord { id: number; name: string; }

export interface Category extends NamedRecord {
  is_active: boolean;
  created_at?: Timestamp;
  updated_at?: Timestamp;
}

export interface User extends NamedRecord {
  email: string;
  role: Role;
  is_active: boolean;
  is_available: boolean;
  categories?: Category[]; // Included in admin agent responses, not /me.
  email_verified_at?: Timestamp | null;
  created_at?: Timestamp;
  updated_at?: Timestamp;
}

export interface Ticket {
  id: number;
  ticket_number: string;
  subject: string;
  description: string;
  priority: Priority;
  status: TicketStatus;
  customer_id: number;
  assigned_agent_id: number | null;
  category_id: number;
  customer?: NamedRecord;
  assigned_agent?: NamedRecord | null;
  category?: NamedRecord;
  sla_started_at: Timestamp;
  sla_warning_at: Timestamp;
  sla_due_at: Timestamp;
  sla_breached_at: Timestamp | null;
  resolved_at: Timestamp | null;
  closed_at: Timestamp | null;
  sla_state: SlaState;
  created_at: Timestamp;
  updated_at: Timestamp;
}

export interface TicketMessage {
  id: number;
  ticket_id: number;
  author_id: number;
  body: string;
  visibility: Visibility;
  author?: NamedRecord; // Loaded in list, may be absent in create response.
  created_at: Timestamp;
  updated_at: Timestamp;
}

export interface Attachment {
  id: number;
  ticket_id: number;
  ticket_message_id: number | null;
  uploaded_by: number;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  scan_status: string;
  created_at: Timestamp;
  updated_at: Timestamp;
}

export interface Activity {
  id: number;
  ticket_id: number;
  actor_id: number | null;
  action: string;
  visibility: Visibility;
  old_values: Record<string, unknown> | unknown[];
  new_values: Record<string, unknown> | unknown[];
  created_at: Timestamp;
}

export interface TicketNotification {
  id: string;
  type: string;
  data: { ticket_id: number; event: string; event_id: number };
  read_at: Timestamp | null;
  created_at: Timestamp;
  updated_at: Timestamp;
}

export interface TicketImport {
  id: number;
  created_by: number;
  filename: string;
  status: "pending" | "processing" | "completed" | "failed";
  total_rows: number;
  processed_rows: number;
  failed_rows: number;
  skipped_rows: number;
  error: string | null;
  created_at: Timestamp;
  updated_at: Timestamp;
}

export type BusinessHours = Partial<Record<
  "0" | "1" | "2" | "3" | "4" | "5" | "6",
  [string, string][]
>>;
export interface SlaRule {
  id?: number;
  sla_policy_id?: number;
  priority: Priority;
  resolution_minutes: number;
  warning_percent: number;
}
export interface SlaPolicy {
  id: number;
  name: string;
  version: number;
  is_active: boolean;
  business_calendar_id: number;
  rules: SlaRule[];
  calendar: {
    id: number;
    name: string;
    timezone: string;
    hours: BusinessHours;
    exceptions: Record<string, [string, string][]> | [];
  };
}
~~~

Do not invent fields: TicketResource currently does not return created_by, permission abilities, agent workload, source references, or SLA target minutes. See section 22.

### Envelope differences

**Single resource/mutation:** most endpoints return {"data": object}. GET categories returns {"data": Category[]} without pagination. The CSRF response, logout response, and notification-read response do not use this envelope.

**Ticket list:** Laravel API Resource collection:

~~~json
{
  "data": [],
  "links": {"first": "...", "last": "...", "prev": null, "next": null},
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 25,
    "total": 0,
    "from": null,
    "to": null,
    "path": "...",
    "links": []
  }
}
~~~

**Raw paginated lists:** messages, attachments, activities, notifications, admin agents, admin policies, and imports:

~~~json
{
  "data": [],
  "current_page": 1,
  "last_page": 1,
  "per_page": 25,
  "total": 0,
  "from": null,
  "to": null,
  "first_page_url": "...",
  "last_page_url": "...",
  "next_page_url": null,
  "prev_page_url": null,
  "path": "...",
  "links": []
}
~~~

Do not read rawPaginator.meta.total. Normalize both shapes:

~~~ts
interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
}
interface ResourcePage<T> { data: T[]; meta: PageMeta; }
interface RawPage<T> extends PageMeta { data: T[]; }
interface Page<T> {
  items: T[];
  currentPage: number;
  lastPage: number;
  perPage: number;
  total: number;
}
export function normalizePage<T>(
  response: ResourcePage<T> | RawPage<T>,
): Page<T> {
  const meta = "meta" in response ? response.meta : response;
  return {
    items: response.data,
    currentPage: meta.current_page,
    lastPage: meta.last_page,
    perPage: meta.per_page,
    total: meta.total,
  };
}
~~~

Use numeric page requests with your own UI route/filter state. API pagination links are API URLs, not frontend navigation URLs. Fixed-size lists use 25 rows; tickets allow per_page=1–100. Aggregate counts may serialize as numbers or numeric strings depending on the database driver; normalize with Number(value ?? 0).

Create/update/assignment responses may omit customer, assigned_agent, and category relations. Refetch ticket detail after mutation instead of assuming those names are present.

## 7. Complete endpoint map

All paths below are relative to /api/v1. Existing routes total **35**.

| Method | Path | Access | Result |
|---|---|---|---|
| GET | /csrf-token | Guest/session | 200 token |
| POST | /register | Guest | 201 user |
| POST | /login | Guest | 200 user |
| POST | /logout | Active authenticated user | 200 message |
| GET | /me | Active authenticated user | 200 user |
| GET | /categories | Active authenticated user | 200 category array |
| GET | /dashboard | Active authenticated user | 200 scoped metrics |
| GET | /tickets | Active authenticated user | 200 resource page |
| POST | /tickets | Active authenticated user | 201 ticket |
| GET | /tickets/{ticket} | Ticket view policy | 200 ticket |
| PATCH | /tickets/{ticket} | Ticket update policy | 200 ticket |
| POST | /tickets/{ticket}/claim | Eligible agent/admin | 200 ticket |
| POST | /tickets/{ticket}/assign | Admin | 200 ticket |
| POST | /tickets/{ticket}/status | Authorized transition actor | 200 ticket |
| POST | /tickets/{ticket}/resolve | Assigned agent/admin | 200 ticket |
| POST | /tickets/{ticket}/close | Owning customer/admin | 200 ticket |
| GET | /tickets/{ticket}/messages | Ticket view policy | 200 raw page |
| POST | /tickets/{ticket}/messages | Reply/internal-note policy | 201 message |
| GET | /tickets/{ticket}/attachments | Ticket view policy | 200 raw page |
| POST | /tickets/{ticket}/attachments | Reply/internal-note policy | 201 metadata |
| GET | /attachments/{attachment}/download | Ticket and attachment policy | Binary |
| GET | /tickets/{ticket}/activities | Ticket view policy | 200 raw page |
| GET | /notifications | Current user only | 200 raw page |
| PATCH | /notifications/{id}/read | Current user only | 200 message |
| GET | /admin/agents | Admin | 200 raw page |
| POST | /admin/agents | Admin | 201 agent |
| PATCH | /admin/agents/{agent} | Admin | 200 agent |
| POST | /admin/categories | Admin | 200 category |
| PATCH | /admin/categories/{category} | Admin | 200 category |
| GET | /admin/sla-policies | Admin | 200 raw page |
| POST | /admin/sla-policies | Admin | 201 policy |
| GET | /admin/imports | Admin | 200 raw page |
| POST | /admin/imports | Admin | 202 import |
| GET | /admin/imports/{import} | Admin | 200 import |
| GET | /admin/imports/{import}/errors | Admin | CSV download |

{ticket}, {attachment}, {agent}, and {import} are numeric database IDs. The visible ticket_number is not the detail-route identifier. Notification IDs are UUID strings.

## 8. Ticket lists and filters

### Request

~~~text
GET /api/v1/tickets?q=login&status=open&priority=critical&category_id=1&page=1&per_page=25
~~~

| Query | Accepted value | UI behavior |
|---|---|---|
| q | String, max 255 | Ticket number or keyword; debounce about 350ms |
| status | One status enum | Label values clearly; no comma-separated list |
| priority | One priority enum | Low/Medium/High/Critical |
| category_id | Integer | Active category selector |
| customer_id | Integer | Admin advanced filter; no lookup API exists |
| assigned_agent_id | Integer | Admin agent selector; agent Assigned tab uses own ID |
| unassigned | 0 or 1 | Send "1", not text "true" |
| from | YYYY-MM-DD | UTC creation-date lower bound |
| to | YYYY-MM-DD | UTC upper bound, not earlier than from |
| sla_state | within/warning/breached | These filters restrict results to active tickets |
| per_page | Integer 1–100 | 25 default |
| page | Positive integer | Conventional paginator |

Frontend SLA badge also accepts met for resolved tickets; the SLA list filter does **not** accept met.

Keep filters/page in the browser query string. Refresh and Back must restore them. Omit empty filters; reset page to 1 when filters change. Allow Clear filters. Cancel obsolete requests.

Columns: ticket number, subject, priority, status, category, assigned agent or Unassigned, owner where appropriate, created time, SLA state/due time, actions.

Active and Resolved/Closed views require care: the current API accepts **one status**, not status[]=... or active=true. Provide individual status chips/subtabs, or use an All tab with existing filters. Do not merge two paginated responses into one fabricated paginated list. A true combined active/finished tab needs a separately documented backend filter extension.

Customer requests must not load all tickets and filter client-side. The backend already scopes ownership. Agents receive assigned tickets plus eligible active unassigned queue tickets. Admins see all.

## 9. Create and edit tickets

### Create

~~~json
{
  "subject": "Cannot sign in",
  "description": "The login page returns an error.",
  "priority": "critical",
  "category_id": 1
}
~~~

Validation:

- Subject required, maximum 255.
- Description required, maximum 50,000.
- Priority required: low/medium/high/critical.
- Category required: existing active category.
- Admin may optionally send customer_id for an existing active customer.
- Normal customer/agent forms omit customer_id.
- Optional source_system and external_ticket_id are paired strings, maximum 100 each. Hide under an admin advanced integration section unless needed.
- Never submit status, assigned_agent_id, author_id, created_by, ticket_number, SLA timestamps, or UI-only field state.

Use GET categories for category choices. Admin results include inactive categories; filter active ones for creation.

POST /tickets returns 201 with data.id and data.ticket_number. Automatic assignment may immediately produce in_progress with an assigned agent; do not assume new tickets remain open.

Files are not accepted inside this JSON create request. Create first, then upload chosen files individually to /tickets/{id}/attachments. If upload fails, retain the created ticket and report the failed files. Retrying creation would duplicate the ticket.

Admin create-on-behalf currently has no customer search/list endpoint. Use an optional numeric Customer ID input with backend validation, or omit that advanced control until a lookup is implemented.

An agent-created ticket can be routed to another agent and become inaccessible under the current view policy. Handle detail 403 after creation gracefully: show the returned ticket number and return to the authorized list. Do not weaken backend authorization or assume creator always has view permission.

### Edit

~~~json
{
  "subject": "Updated subject",
  "description": "More detail"
}
~~~

Authorized staff may also send priority. Customers must omit priority entirely. Category, owner, and assignment are not editable through PATCH /tickets/{id}. Status changes use dedicated endpoints.

Only active tickets are editable. Disable editing after resolved/closed. Staff priority changes recalculate SLA using the original SLA start; refetch detail and display updated server values.

## 10. Ticket details and lifecycle

Load independent resources concurrently after the ticket itself is authorized, with separate loading/error/pagination state:

- GET /tickets/{id}.
- GET /tickets/{id}/messages?page=1.
- GET /tickets/{id}/attachments?page=1.
- GET /tickets/{id}/activities?page=1.

Ticket header: number, subject, status, priority, due time/SLA badge. Main pane: description, conversation, reply composer. Secondary pane: customer, category, assigned agent, timestamps, permitted actions. Timeline can be a tab/section.

### Allowed transitions

| Current | Allowed next |
|---|---|
| open | in_progress, resolved |
| in_progress | waiting_for_customer, resolved |
| waiting_for_customer | in_progress, resolved |
| resolved | closed |
| closed | None |

Transition eligibility and actor authorization are separate checks. A customer cannot resolve simply because the transition exists.

Examples:

~~~ts
await apiRequest("/tickets/" + id + "/status", {
  method: "POST",
  body: { status: "waiting_for_customer" },
});
await apiRequest("/tickets/" + id + "/resolve", { method: "POST" });
await apiRequest("/tickets/" + id + "/close", { method: "POST" });
~~~

Prefer dedicated resolve/close endpoints for those buttons. Show a confirmation and pending state, then refetch ticket, history, and applicable lists/dashboard. Do not optimistically mutate assignment/status for concurrency-sensitive actions.

Resolved and closed tickets accept neither messages nor uploads. Waiting for customer does not pause SLA. Reopening and deleting tickets are not implemented; do not render those actions.

## 11. Assignment and permissions

### Manual assignment

Admin uses POST /tickets/{id}/assign:

~~~json
{"assigned_agent_id": 12}
~~~

Read the paginated /admin/agents list. Filter candidates by:

- is_active=true.
- is_available=true.
- categories contains ticket.category_id.

The endpoint is paginated at 25. A selector must page through results or offer Load more; the first page is not the complete agent population. There is no server-side agent search or workload field. Do not invent workload values. The backend revalidates eligibility regardless of client filtering.

A resolved/closed ticket cannot be assigned. There is no unassign endpoint.

### Claim

Eligible support agent or admin sends an empty POST /tickets/{id}/claim. Success is 200. If another agent already accepted it, 409 means a business conflict, not a generic crash.

On 409: show "This ticket is no longer available", refresh the queue/detail, and remove stale Claim controls. Detail refresh may now return 403 to the losing agent; navigate back to their queue without disclosing the new conversation.

### UI action rules

| Action | Customer | Agent | Admin |
|---|---|---|---|
| Create | Active account | Active account | Active account |
| View | Own ticket | Assigned, or eligible active unassigned queue | All |
| Edit subject/description | Own active ticket | Own assigned active ticket | Any active ticket |
| Change priority | Never | Own assigned active ticket | Any active ticket |
| Claim | Never | Available, eligible, open, unassigned | Open, unassigned; availability checked by action |
| Assign/reassign | Never | Never | Active ticket |
| Public reply/upload | Own active ticket | Own assigned active ticket | Any active ticket |
| Internal note | Never | Own assigned active ticket | Any active ticket |
| Resolve | Never | Own assigned active ticket | Any active ticket |
| Close | Own resolved ticket | Never | Resolved ticket |
| Admin settings/imports | Never | Never | Yes |

The frontend does not receive a permissions object or agent category mappings from /me. Derive **presentation hints**, not security decisions. For an agent, the backend-authorized queue/list/detail establishes category visibility; /me provides availability. Do not call admin-only agent endpoints from an agent session.

For an authorized ticket detail:

~~~ts
const active = ["open", "in_progress", "waiting_for_customer"]
  .includes(ticket.status);
const admin = user.role === "admin";
const assignedAgent = user.role === "agent"
  && ticket.assigned_agent_id === user.id;
const owner = user.role === "customer" && ticket.customer_id === user.id;

const canEdit = active && (admin || assignedAgent || owner);
const canChangePriority = active && (admin || assignedAgent);
const canReply = active && (admin || assignedAgent || owner);
const canAddNote = active && (admin || assignedAgent);
const canResolve = active && (admin || assignedAgent);
const canClose = ticket.status === "resolved" && (admin || owner);
const canAssign = active && admin;
const canClaim = ticket.status === "open"
  && ticket.assigned_agent_id === null
  && user.is_available
  && (admin || user.role === "agent");
~~~

Also require user.is_active for controls. A server 403 always overrides these hints. Staff may view internal content on an authorized queue ticket without being allowed to add a note before claiming.

## 12. Conversations and internal notes

Public reply:

~~~json
{"body":"Please try resetting your password.","visibility":"public"}
~~~

Internal note:

~~~json
{"body":"Investigate the account lockout logs.","visibility":"internal"}
~~~

POST /tickets/{id}/messages returns 201. Body required, maximum 50,000. Do not send author_id.

- Customers see only public messages and no internal-note toggle.
- Staff see returned public/internal messages; internal notes need an explicit visual label and distinct surface.
- Only assigned agent/admin sees the enabled internal-note composer.
- Messages are ordered oldest first by ID and paginated at 25; newest replies may be on the last page.
- After sending, fetch the appropriate newest page or show a "View latest messages" control. Do not insert a new reply into the wrong page and duplicate it on refetch.
- New-message response may omit author. Refetch or use the current actor's name as a temporary label.
- Attach files to a reply by creating the message first, then uploading with its returned id as ticket_message_id.
- If some uploads fail, report partial success and retry only those uploads.
- Retrying a reply after an unknown network result can duplicate it: the API has no message idempotency key.
- Never fabricate internal notes in customer caches; clear all private queries when switching/logout accounts.

Do not auto-transition waiting_for_customer after a reply; current backend does not implement that behavior.

## 13. Attachments and downloads

Upload is multipart:

~~~ts
const form = new FormData();
form.append("file", file);
if (messageId !== undefined) {
  form.append("ticket_message_id", String(messageId));
}
const result = await apiRequest<{ data: Attachment }>(
  "/tickets/" + ticketId + "/attachments",
  { method: "POST", body: form },
);
~~~

- One file per request.
- Maximum 10 MB/file.
- Permitted extensions/types: PDF, PNG, JPG/JPEG, TXT, DOCX.
- Client checks improve feedback; server MIME/extension/size checks remain authoritative.
- A linked message must belong to the same ticket.
- Internal-note attachment inherits internal access restrictions.
- Backend exposes metadata only; storage_key and disk are hidden.
- There is no attachment-delete endpoint or public storage URL.
- Standard fetch does not supply upload percentage events. Use an indeterminate uploading state or an explicitly implemented XHR client for real upload progress.

Use authenticated binary download requests so errors can be displayed. Reference helper, using ApiError from section 5:

~~~ts
export async function downloadFile(
  path: string,
  suggestedName: string,
): Promise<void> {
  const response = await fetch("/api/v1" + path, {
    credentials: "same-origin",
    headers: { Accept: "application/octet-stream, application/json" },
    cache: "no-store",
  });
  if (!response.ok) {
    const payload = await response.json().catch(() => null);
    throw new ApiError(response.status, payload?.message ?? "Download failed.");
  }
  if (response.headers.get("Content-Type")?.includes("application/json")
    || !response.headers.get("Content-Disposition")?.includes("attachment")) {
    throw new Error("Expected an attachment; received another response.");
  }
  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = suggestedName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
~~~

Call with "/attachments/" + attachment.id + "/download" and attachment.original_name. Imports use the same helper with an error-CSV path/name. Keep download paths API-relative and supplied by the application; do not accept arbitrary external URLs.

## 14. SLA display

Use ticket.sla_state and server timestamps:

| Value | Label |
|---|---|
| within | Within SLA |
| warning | Approaching breach |
| breached | SLA breached |
| met | Resolved within SLA |

Display due date/time in Asia/Dhaka by default, with the timezone label. Parse UTC timestamps as instants; do not append another timezone offset.

~~~ts
export function formatDateTime(value: string | null): string {
  if (!value) return "—";
  return new Intl.DateTimeFormat("en-GB", {
    timeZone: "Asia/Dhaka",
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}
~~~

A wall-clock "due in" countdown is optional and must be labelled accordingly. Do not label wall-clock minutes as remaining business minutes; the latter requires calendar-aware computation not supplied by this API.

Business assumptions:

- Sunday–Thursday, 09:00–18:00, Asia/Dhaka by default.
- Low 2,880; Medium 1,440; High 480; Critical 120 **business minutes**.
- Warning default at 80% consumed business time.
- Thursday 17:00 + 120 business minutes = Sunday 10:00, absent an exception.
- Policy changes affect new tickets; existing snapshots remain.
- Priority changes use the original policy version/start.
- Resolved/closed tickets stop SLA monitoring.
- A historical sla_breached_at may remain after priority extension; show a separate "Previously breached" marker instead of hiding that history.
- Resolved SLA state is based on the backend result, not a continuing frontend timer.

Server-provided status can become stale over time; periodically refetch active detail or show a refresh control. Do not send frontend-calculated due timestamps back.

## 15. Dashboard

GET /dashboard:

~~~json
{
  "data": {
    "by_status": {"open": 10, "in_progress": 8, "resolved": 20},
    "by_priority": {"low": 4, "medium": 10, "high": 8, "critical": 16},
    "unassigned": 3,
    "sla_breached": 2,
    "sla_warning": 4
  }
}
~~~

Absent group keys mean zero. Empty grouped maps may serialize as empty arrays; use key lookup with a zero fallback.

Cards:

- Open = by_status.open.
- Active = open + in_progress + waiting_for_customer.
- Unassigned = unassigned.
- Critical = by_priority.critical; this includes all statuses in the authorized scope.
- SLA breached = sla_breached, active only.
- Approaching breach = sla_warning, active only.
- Resolved = by_status.resolved.
- Closed may be a separate card or summary count.

Show counts by priority with a simple table/bar view. Link cards to valid existing filters. If a card combines multiple statuses, its link must not pretend the API supports a combined-status filter.

Metrics are role-scoped and cached for 30 seconds. Refetching immediately after mutation may still show old values. Show last refresh time; refresh around every 30–60 seconds only while visible. Avoid an aggressive polling loop.

Tickets by agent is not returned. Do not build a misleading chart from one ticket page. It needs a backend aggregate endpoint/field if selected as an enhancement.

## 16. Notifications

GET /notifications?page=1 returns a raw paginator. Render event label, created time, read state, and a link to /tickets/{data.ticket_id}.

PATCH /notifications/{uuid}/read accepts an empty body and returns {"message":"Marked as read."}. Update read state after success. There is no unread-count endpoint, mark-all-read endpoint, or WebSocket channel.

- A first-page unread count is only "unread on this page"; do not claim it is a global count.
- Fetch around every 60 seconds while the page/tab is visible, or use manual refresh.
- Do not require a notification to appear immediately after a mutation: outbox scheduling/queues are asynchronous.
- Handle expired ticket access after reassignment: a notification link can return 403.
- Events contain IDs/event names, not conversation body or ticket subject. Fetch authorized detail if needed.
- The notification recipient bug for customer-originated ticket.created is listed in section 22; frontend cannot fix event creation.

Event labels include ticket.created, ticket.assigned, ticket.claimed, ticket.replied, ticket.resolved, ticket.closed, ticket.in_progress, ticket.waiting_for_customer, ticket.updated, sla.warning, and sla.breach.

## 17. Administrator: categories and agents

### Categories

GET /categories returns all categories to admins, active only to other roles.

POST /admin/categories and PATCH /admin/categories/{id}:

~~~json
{"name":"Technical","is_active":true}
~~~

Name required, unique, maximum 255. PATCH still requires name, even when toggling active state. Send the existing name along with is_active. Both return 200, not 201.

Provide list, create/edit dialog, active/inactive badges, and activate/deactivate. There is no category deletion endpoint. After changes, refresh category selectors.

### Agents

GET /admin/agents returns a raw paginator, 25/page, including categories.

Create:

~~~json
{
  "name":"Agent Name",
  "email":"agent@example.com",
  "password":"user-entered-password",
  "category_ids":[1,2]
}
~~~

Name/email required and maximum 255; email unique; password minimum 12; category_ids required array of distinct existing IDs. The backend does not require at least one category, but an unmapped agent receives no category queue work. Explain that state.

PATCH /admin/agents/{id} supports name, is_active, is_available, category_ids. Creation email/password cannot be edited through this endpoint. Do not render a working email/password-edit form without an extension.

Deactivation is a soft operational change, not user deletion. Existing assignments are not automatically redistributed. Explain this before deactivation.

## 18. Administrator: SLA policies

GET /admin/sla-policies returns policy versions with calendar and rules. POST creates and **immediately activates** a new version, deactivating the previous active one. No draft, update, delete, standalone activate, or standalone calendar endpoint exists.

Provide:

- Version history with active badge.
- Read-only detail of existing rules/calendar.
- "Create new version" initialized from an existing version if selected.
- Policy name and timezone input.
- Seven weekdays with closed/open toggle and multiple time intervals.
- Exception date list with closed/full replacement intervals.
- Four priority rows: target minutes and warning percent.
- Confirmation explaining that only new tickets use the new version.

Payload:

~~~json
{
  "name":"Standard",
  "timezone":"Asia/Dhaka",
  "hours":{
    "0":[["09:00","12:00"],["13:00","18:00"]],
    "1":[["09:00","18:00"]],
    "2":[["09:00","18:00"]],
    "3":[["09:00","18:00"]],
    "4":[["09:00","18:00"]],
    "5":[],
    "6":[]
  },
  "exceptions":{"2026-12-16":[]},
  "rules":[
    {"priority":"low","resolution_minutes":2880,"warning_percent":80},
    {"priority":"medium","resolution_minutes":1440,"warning_percent":80},
    {"priority":"high","resolution_minutes":480,"warning_percent":80},
    {"priority":"critical","resolution_minutes":120,"warning_percent":80}
  ]
}
~~~

Rules:

- Weekday keys: 0 Sunday through 6 Saturday.
- hours must contain at least one interval across the week.
- Each day is a list of two-element [start,end] arrays, using HH:mm.
- Intervals sorted, start before end, no overlap.
- An exception replaces the full normal day; [] means closed.
- Exception key must be a real YYYY-MM-DD date.
- Split overnight intervals across days; end=00:00 after a late start is invalid.
- Exactly four rules, one unique priority each.
- resolution_minutes integer 1–525600.
- warning_percent integer 1–99.
- Browser timezone defaults are not a substitute for the selected policy timezone.
- Send an empty exceptions object when no exceptions are selected; backend responses may represent an empty value as [].

Map dotted validation keys such as hours.0.1.0 and rules.2.resolution_minutes to the matching editor row. Do not divide durations by 60 and label them elapsed calendar hours; label them business minutes/hours.

## 19. Administrator: CSV imports

Upload using multipart file to POST /admin/imports. CSV extension required, maximum 50 MB. Result: 202 with data.id. Navigate to that import's detail; background completion is separate.

Required unique headers:

~~~csv
customer_email,category,subject,description,priority
customer@example.com,Technical,Cannot sign in,Login fails,critical
~~~

Optional paired columns: source_system,external_ticket_id. If one has a value, the other must also have a value. Both blank is permitted. Customer email/category must match existing active records. Priority values use lowercase enum strings. Offer a sample-template download generated in the browser; do not invent an API template endpoint.

Import detail uses GET /admin/imports/{id}. Poll every 5 seconds while status is pending/processing, stop on completed/failed or component unmount, and pause when hidden. Use a non-overlapping polling loop and exponential backoff after transient errors. Import polling plus other calls must remain below the support request limit.

Progress semantics:

- total_rows is discovered during streaming and grows while processing.
- processed_rows includes successes, invalid rows, and skipped rows.
- failed_rows = invalid rows.
- skipped_rows = duplicate external references.
- successful rows = processed_rows - failed_rows - skipped_rows.
- While processing, processed_rows/total_rows is **not a reliable final percentage** because total_rows is not precomputed.
- Show an indeterminate progress bar plus exact processed/error/skip counts until completed.
- completed can include failed/skipped rows; show the summary, not a generic all-success message.
- failed is a file/job-level terminal state; valid rows processed before failure may already exist.
- error contains file-level failure information if present.

List imports via /admin/imports?page=N. Download errors through /admin/imports/{id}/errors. There is no inline error-row-list, import cancel, delete, or retry endpoint. Do not label re-upload as retry: rows without external references can be duplicated across new import records.

## 20. Audit timeline

GET /tickets/{id}/activities?page=N returns latest-first rows. Customers receive public rows only. Render actions as readable event labels plus old/new changes.

- Actor names are not hydrated. Use "User #12" for a known actor_id, or current user's name when their ID matches. Null actor_id means System.
- Never assume action actor is ticket customer or assigned agent.
- old_values/new_values can be empty arrays or objects; normalize before displaying fields.
- Render field labels and safe text values rather than dumping unescaped HTML/JSON.
- Record dates/times with timezone labels.
- Use pagination/load-more without duplicate records.
- Audit activity is immutable; no edit/delete controls.

Ticket resource currently omits created_by. Use the ticket.created activity's actor_id for a creator-ID display if that activity is loaded. Do not fabricate a creator name.

## 21. Errors, loading, and refresh

### HTTP behavior

| Status | Frontend handling |
|---|---|
| 200/201 | Confirm success, refresh affected data |
| 202 | Import queued; begin status polling |
| 401 | Clear private state, preserve safe draft where appropriate, go to login |
| 403 | Forbidden/inactive account; remove stale controls, show recoverable state |
| 404 | Missing resource page; do not imply ownership |
| 409 | Conflict/invalid transition; refresh current state, preserve draft |
| 419 | Clear/refetch CSRF, ask user to resubmit; avoid generic mutation replay |
| 422 | Inline field errors and form summary |
| 429 | Respect Retry-After; disable rapid repeated submission/polling |
| 500/503/network | Retry/error state, retain form values; no false success |

Validation shape:

~~~json
{
  "message":"The given data was invalid.",
  "errors":{
    "subject":["The subject field is required."],
    "rules.0.resolution_minutes":["The value must be at least 1."]
  }
}
~~~

The message text can vary; use field keys/status, not exact English-string matching.

Login/register limit: 5/minute per email+IP and 30/minute per IP. Authenticated API limit: 120/minute per user. Import upload adds 10/hour per user.

Each resource needs independent pending, ready, empty, forbidden, and retry states. Avoid an all-or-nothing detail page when only the timeline request fails.

After mutations, refresh relevant ticket detail, list, conversation, attachments, audit, and dashboard. Keep page/filter state. Dashboard cache may delay fresh metrics by 30 seconds.

An optional query-cache library may be used, but its invalidation keys must include user identity/scope. On logout/account switch, cancel ongoing requests, clear caches, and prevent late responses from restoring the previous user's data.

## 22. Backend limitations and fallbacks

These are actual integration boundaries, not endpoints the frontend can assume.

| Missing/current behavior | Required frontend response | Optional backend enhancement |
|---|---|---|
| No React/Inertia runtime yet | Install/configure presentation stack in this repo | Add UI shell routes and root template |
| /me has no category IDs/abilities | Use role, availability, authorized ticket results, and server errors | Authorized ability flags/current agent category IDs |
| No customer list/search | Omit selector or use admin numeric customer ID | Admin-only paginated customer lookup |
| Ticket lacks created_by/creator name | Audit actor ID fallback; no guessed name | Authorized creator fields/relation |
| Audit lacks actor relation | Display actor ID/System | Eager-load safe actor name |
| Single status filter only | Individual status tabs | Allowlisted active/finished status group filter |
| No agent workload metrics or by-agent dashboard | Do not manufacture totals | Scoped aggregate endpoint/fields |
| No unread notification total | Page-level unread label only | Scoped unread-count endpoint |
| New ticket/customer actor notification may be skipped | Do not promise it as delivered | Fix recipient selection/outbox event creation |
| No message/create/upload idempotency key | Avoid blind mutation retries | Dedicated idempotency contract |
| Agent self-created ticket may become inaccessible | Handle post-create detail 403 | Deliberate creator-view/ownership policy decision |
| No reopen/delete/unassign | Hide these actions | Separate authorized domain actions if required |
| Policies immediately activate on creation | Confirm new-version activation | Draft/activation lifecycle if needed |
| No import cancel/retry/error JSON endpoint | Poll/read summary and CSV only | Explicit operations if required |
| Mail log driver by default | Test in-app data, do not promise delivered mail | Configure SMTP and verify workers |
| No real-time broadcasting | Poll within limits/manual refresh | Authorized broadcasting channels |

Frontend implementation should work with existing capabilities first. If extending the backend, label the extension, add validation/policy/tests, and update the contract. Do not quietly invent routes or mock production responses.

## 23. Implementation sequence

1. Verify dependencies and backend routes; preserve existing user changes.
2. Install React/TypeScript/Inertia presentation dependencies and configure Vite/root template/UI routes.
3. Build API client, pagination adapters, auth provider, and global error handling.
4. Build login/register/logout with CSRF rotation and session restore.
5. Build layouts, role-aware navigation, responsive shared components.
6. Build category loading, ticket lists/search/filter URL state, create/edit.
7. Build detail, transitions, manual assignment, queue claiming/conflict handling.
8. Build paginated conversations, internal notes, uploads/downloads, audit.
9. Build dashboard and notification center with bounded refresh.
10. Build administrator category/agent management.
11. Build SLA policy/calendar/exception editor with real validation feedback.
12. Build CSV upload, history, status polling, summaries, error download.
13. Test complete role workflows, production build, refresh/deep links, and accessibility.
14. Update README with exact frontend setup and document any backend extensions.

## 24. Acceptance checklist

### Build and navigation

- [ ] Production npm build succeeds.
- [ ] TypeScript checks succeed.
- [ ] Laravel serves React/Inertia pages in the same repository.
- [ ] Direct reload/deep links work on all proposed UI paths.
- [ ] API routes still return JSON; UI routes return page responses.
- [ ] Layout works on mobile and desktop; keyboard navigation works.
- [ ] No placeholder counters, fake production data, or dead action buttons.

### Authentication and security

- [ ] Register creates customer only; password confirmation works.
- [ ] Login/logout/session restore work against the real backend.
- [ ] CSRF refresh after login/register/logout and 419 handling verified.
- [ ] 401/403/404/422/429 states behave correctly.
- [ ] Private state clears on logout/account switch.
- [ ] User text stays escaped; no client-held password/session token.
- [ ] Customer cannot see internal notes/attachments in UI or network responses.
- [ ] Admin routes/controls are hidden and guarded for other roles.

### Ticket workflow

- [ ] Customer creates, edits allowed fields, replies, uploads/downloads, views own history, closes resolved ticket.
- [ ] Agent sees scoped queue/assigned tickets, claims, replies/notes, changes allowed status/priority, resolves.
- [ ] Administrator assigns/reassigns only eligible active/available agents.
- [ ] Two-agent claim conflict shows feedback and refreshes the losing agent's queue.
- [ ] Resolved/closed composers/uploads are disabled.
- [ ] Automatic assignment response is handled.
- [ ] Ticket lists support every implemented filter and correct pagination envelope.
- [ ] Missing relations after mutations trigger detail refetch.
- [ ] Reply/file partial failure does not duplicate ticket/message creation.

### SLA, dashboard, notifications

- [ ] Due times use server UTC values displayed in labelled local timezone.
- [ ] Business-hour explanation and historical breach display are accurate.
- [ ] Dashboard counts use server aggregates, zero defaults, and cache-aware refresh.
- [ ] Notification read/link behavior works; unavailable tickets handled.
- [ ] Worker/scheduler delay is represented without fake immediate delivery.

### Administrator workflow

- [ ] Category create/edit/activation includes required name.
- [ ] Agent create/edit/category mapping/availability/deactivation works.
- [ ] Paginated agent selectors do not silently omit later pages.
- [ ] New SLA policy version, multi-shifts, holidays, and dotted validation errors work.
- [ ] SLA activation confirmation explains existing-ticket snapshots.
- [ ] CSV upload returns queued state; processing/error/skip counts are accurate.
- [ ] No misleading percentage while CSV total_rows is still growing.
- [ ] Polling stops on completion/failure/unmount and respects rate limits.
- [ ] Error CSV downloads through authorized API.

### Verification deliverables

Run the backend suite to detect regressions:

~~~sh
php artisan test --compact
~~~

Run the final frontend build and configured typecheck/test commands. Do not claim a frontend test command exists until it is added to package.json.

Add meaningful frontend tests for auth/CSRF, permission controls, pagination adapters, claim conflict, internal-note separation, uploads, and import polling. End-to-end role scenarios should use isolated test accounts/database, not real production data.

Record completed checks, skipped checks, unresolved backend extensions, and runtime limitations in the delivery note. Verify against a real browser and backend, not only screenshots or fixtures.

## 25. Copy-ready implementation prompt

Give this entire document to the frontend developer/coding agent, together with repository access:

> Implement the complete frontend for this existing Laravel Support Ticket & SLA Management backend. Read docs/FRONTEND_IMPLEMENTATION.md, docs/API.md, README.md, routes/backend.php, Form Requests, TicketResource, and TicketPolicy before coding.
>
> Use React 19, TypeScript, Inertia 3, Tailwind CSS 4, and accessible shadcn/ui components in this Laravel repository. Configure the missing frontend runtime, Vite entry points, Inertia root template, and presentation GET routes. Keep existing /api/v1 session/CSRF JSON endpoints and reuse them through a typed fetch client. Use Inertia for page navigation; ordinary API fetch for JSON mutations. Preserve backend domain rules and existing user changes.
>
> Build customer, agent, and admin layouts; login/register/logout/session restore; dashboard; URL-backed ticket lists/search/filters; ticket creation/edit/detail; claim/assign/resolve/close; public replies/internal notes; private attachments/downloads; audit; notifications; admin categories/agents; SLA policy/calendar/exception editor; CSV import upload/history/progress/error CSV.
>
> Follow the exact request fields, permissions, enum values, response envelopes, pagination differences, CSRF rotation, conflict handling, and background-processing semantics documented here. Do not invent endpoints, permission props, customer lookup, unread totals, agent metrics, upload percentages, or import completion percentages. Use the documented fallbacks. Treat any backend extension as an explicit, tested change.
>
> Connect every screen to real API data. Handle loading, empty, validation, forbidden, session-expired, conflict, rate-limit, and network states. Keep forms/drafts safe during failures. Render user content as escaped text, clear private caches on logout, and verify internal-note/attachment isolation.
>
> Complete the acceptance checklist. Run build/typechecks, appropriate frontend tests, backend regression tests, and real-browser customer/agent/admin flows. Update setup instructions and report exactly what passed and what remains. Do not stop with static mockups, scaffold pages, or a plan.

## Related documentation

- [Backend API reference](API.md)
- [Backend architecture](ARCHITECTURE.md)
- [Project setup and operations](../README.md)

Document prepared from the repository backend contract on 2026-10-13.

