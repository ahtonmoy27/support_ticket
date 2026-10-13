# Backend design

Controllers validate/authorize and call application actions under app/Domain. Eloquent models remain in app/Models to follow the existing Laravel convention. Dedicated services calculate deadlines, evaluate SLAs, query authorized tickets, and write audits/outbox records.

## Transaction boundaries

Ticket creation, assignment, priority edits, lifecycle changes, conversations, attachments, and escalation recording pair business changes with audit records inside transactions. Creation formats TKT-{UTC year}-{global auto-increment ID} before commit; sequence gaps are intentional. Source-system identifiers and escalation cycles have unique constraints.

Assignment-affecting operations acquire the assignment row in support_locks before reading workloads or locking ticket/agent rows. This serializes allocation across workers and avoids stale least-loaded decisions under MySQL REPEATABLE READ. Claims reauthorize after locking and return 409 if no longer open/unassigned. Eligible agents must be active, available, and mapped to the ticket category. Ties resolve by ascending agent ID. Administrators can claim for themselves; manual assignment targets eligible agents.

SLA policy activation has its own database mutex so concurrent activation cannot leave multiple active policies. Each new policy owns a new calendar and four new rules. Tickets snapshot the calendar, target, warning percentage, and computed UTC deadlines. Priority changes preserve the original start, select the new priority from the original policy version, and retain earlier breach timestamps.

## Authorization

Policies distinguish ownership, queue visibility, assigned-agent operations, and administrator privileges. Search scopes are applied before filters or pagination. Public/internal message and attachment queries filter sensitive data at the database layer. Internal attachment downloads authorize independently. Audit rows for private actions use internal visibility. Notifications contain event metadata rather than message bodies and recheck authorization at delivery time.

One exclusive role per user is sufficient for this three-role implementation. Adding multiple roles later would require a role membership model and corresponding policy updates.

## SLA time

The calculator operates on daily intervals in the calendar timezone and consumes elapsed seconds within those intervals, returning UTC. It advances interval-by-interval, not minute-by-minute, and preserves input seconds. Calendar exceptions replace regular hours. DST behavior is covered by a spring-forward test; ambiguous local shift boundaries need explicit operational calendar conventions.

The warning threshold uses ceil(target_minutes × warning_percent / 100) business minutes. Active tickets include waiting_for_customer. Resolution at or after the due instant is breached; monitoring records the deadline as the breach timestamp rather than scan time.

The scheduler checks active tickets every minute, in bounded batches. It locks/rechecks each ticket and records one warning or breach per cycle/level. A late scan emits breach only. Waiting does not pause time; reopening is excluded, so the current cycle is always 1.

## Import and notification reliability

CSV processing reads a private stream and processes rows individually with transactional row journals. Each row's ticket, audit/outbox, result, and counters commit together. Retried workers skip committed rows, and external references deduplicate across imports. Invalid rows are journaled separately. Per-import queue overlap locks prevent simultaneous processing. Pending imports are redispatched by the scheduler to recover a failed initial queue publication. Worker failures are observable through Laravel failed jobs.

Outbox rows are committed with business changes and later published by tickets:dispatch-outbox. Publication failures leave rows pending. Concurrent dispatchers lock/recheck rows. Mail is at-least-once; deterministic event/recipient database-notification IDs prevent repeated in-app notifications. Queue workers for imports and notifications run independently.

## Production boundary

The implementation and MySQL concurrency tests establish correctness for the tested paths, not a performance certification. Shared Redis/cache, private shared file storage, proper SMTP, HTTPS, database backups, monitoring, and process supervision are deployment responsibilities. Horizontal scale will eventually require replacing the assignment mutex with ordered workload counters/locks and benchmarking parallel import chunks. Redis/Horizon monitoring, malware scanning, external integrations, and frontend pages are not implemented here.
