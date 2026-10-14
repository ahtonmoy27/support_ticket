# Backend endpoint reference

Base path: /api/v1. All responses are JSON except private file downloads and import error CSVs. Session cookies and CSRF tokens are required; see README. Authenticated routes reject inactive accounts. Standard responses: 201 creation, 202 accepted import, 401 unauthenticated, 403 forbidden, 404 missing resource, 409 business conflict, 422 validation, 429 rate limit.

| Method | Path | Input / purpose |
|---|---|---|
| GET | /csrf-token | Session-bound CSRF token |
| POST | /register | name, email, password, password_confirmation; customer only |
| POST | /login | email, password |
| POST | /logout | End session |
| GET | /me | Current actor |
| GET | /categories | Active categories; admin sees inactive too |
| GET | /tickets | Authorized ticket page |
| POST | /tickets | subject, description, category_id, priority; admin may provide customer_id |
| GET | /tickets/{ticket} | Authorized detail |
| PATCH | /tickets/{ticket} | subject, description; authorized staff may change priority |
| POST | /tickets/{ticket}/claim | Atomic acceptance; competing claimant gets 409 |
| POST | /tickets/{ticket}/assign | Admin: assigned_agent_id |
| POST | /tickets/{ticket}/status | Staff: status transition |
| POST | /tickets/{ticket}/resolve | Staff resolution |
| POST | /tickets/{ticket}/close | Admin or owning customer; resolved tickets only |
| GET | /tickets/{ticket}/messages | Paginated conversation; customer sees public only |
| POST | /tickets/{ticket}/messages | body, visibility (public/internal) |
| GET | /tickets/{ticket}/attachments | Authorized metadata; customer sees public only |
| POST | /tickets/{ticket}/attachments | Multipart file, optional ticket_message_id belonging to this ticket |
| GET | /attachments/{attachment}/download | Authorized private download |
| GET | /tickets/{ticket}/activities | Authorized timeline; customer sees public only |
| GET | /dashboard | Scoped counts by status/priority, unassigned, SLA warning/breach |
| GET | /notifications | Current user's notifications |
| PATCH | /notifications/{id}/read | Mark owned notification read |
| GET/POST | /admin/agents | List/create; name, email, password, category_ids |
| PATCH | /admin/agents/{agent} | name, is_active, is_available, category_ids |
| POST | /admin/categories | name, optional is_active |
| PATCH | /admin/categories/{category} | name, optional is_active |
| GET | /admin/sla-policies | Versions with rules/calendars |
| POST | /admin/sla-policies | Create and activate a new immutable version |
| GET/POST | /admin/imports | List/upload multipart CSV file |
| GET | /admin/imports/{import} | status, total/processed/failed/skipped row counters |
| GET | /admin/imports/{import}/errors | Download invalid-row report |

List filters: q, status, priority, category_id, customer_id, assigned_agent_id, unassigned=1, from/to (YYYY-MM-DD, UTC boundaries), sla_state (within/warning/breached), per_page (1–100), page. Filters never override the actor's visibility scope. Exact ticket numbers and keywords are supported.

Statuses: open, in_progress, waiting_for_customer, resolved, closed. Priorities: low, medium, high, critical.

SLA policy example (POST /admin/sla-policies):

```json
{
  "name": "Standard",
  "timezone": "Asia/Dhaka",
  "hours": {
    "0": [["09:00", "12:00"], ["13:00", "18:00"]],
    "1": [["09:00", "18:00"]],
    "2": [["09:00", "18:00"]],
    "3": [["09:00", "18:00"]],
    "4": [["09:00", "18:00"]]
  },
  "exceptions": {"2026-12-16": []},
  "rules": [
    {"priority": "low", "resolution_minutes": 2880, "warning_percent": 80},
    {"priority": "medium", "resolution_minutes": 1440, "warning_percent": 80},
    {"priority": "high", "resolution_minutes": 480, "warning_percent": 80},
    {"priority": "critical", "resolution_minutes": 120, "warning_percent": 80}
  ]
}
```

Weekdays use 0=Sunday through 6=Saturday. Intervals must be sorted, non-overlapping, and contained within one day. Exception dates replace the day's normal intervals; an empty list closes the date. Overnight shifts must be split across two dates. Timestamps in responses are UTC.

Ticket creation optionally accepts source_system/external_ticket_id together. Those identifiers are unique; request duplicates receive 422, and import duplicates are reported as skipped. No API accepts trusted author_id, created_by, or arbitrary status/assignment changes through ticket creation/update.

## Response contracts

Ticket resources always include created_by (the immutable creator user ID). Ticket list/detail responses additionally include creator: {id, name}, loaded without per-ticket queries. customer_id identifies the requester and may differ when an administrator creates on behalf of a customer. Creator email/password are not exposed.

Paginated resource endpoints return {data: [...], links: {...}, meta: {current_page, last_page, per_page, total, ...}}. Categories return an unpaginated {data: [...]} envelope; single resources return {data: {...}}. GET /admin/health is restricted to administrators and returns {data: {healthy, checked_at, metrics, issues}}. Import retry is an operator CLI action, not an HTTP endpoint.

Customers receive ticket.created acknowledgements even for tickets they create themselves. Other event notifications exclude the actor; mail delivery is queued and at-least-once. Internal event recipients and contents remain restricted.
