# Backend design

Controllers validate/authorize and call application actions under app/Domain. Eloquent models remain in app/Models to follow the existing Laravel convention. Dedicated services calculate deadlines, evaluate SLAs, query authorized tickets, and write audits/outbox records.

## Transaction boundaries

Ticket creation, assignment, priority edits, lifecycle changes, conversations, attachments, and escalation recording pair business changes with audit records inside transactions. Creation formats TKT-{UTC year}-{global auto-increment ID} before commit; sequence gaps are intentional. Source-system identifiers and escalation cycles have unique constraints.

Assignment-affecting operations acquire a category row lock, then affected ticket/agent locks. Automatic assignment locks eligible agents in ascending ID order and reads active workloads with a locking aggregate query, avoiding stale counts under MySQL REPEATABLE READ and agent-per-query N+1 work. Different categories can proceed independently; overlapping eligible-agent sets still coordinate through agent locks. Claims reauthorize the locked ticket and return 409 if it is no longer open/unassigned. Eligible agents must be active, available and mapped to the category. Ties resolve by ascending agent ID. Transactions retry deadlocks up to three times. Administrators can claim for themselves; manual assignment targets eligible agents.

SLA policy activation has its own database mutex so concurrent activation cannot leave multiple active policies. Each new policy owns a new calendar and four new rules. Tickets snapshot the calendar, target, warning percentage, and computed UTC deadlines. Priority changes preserve the original start, select the new priority from the original policy version, and retain earlier breach timestamps.

## Authorization

Policies distinguish ownership, queue visibility, assigned-agent operations, and administrator privileges. Search scopes are applied before filters or pagination. Public/internal message and attachment queries filter sensitive data at the database layer. Internal attachment downloads authorize independently. Audit rows for private actions use internal visibility. Notifications contain event metadata rather than message bodies and recheck authorization at delivery time.

One exclusive role per user is sufficient for this three-role implementation. Adding multiple roles later would require a role membership model and corresponding policy updates.

## SLA time

The calculator operates on daily intervals in the calendar timezone and consumes elapsed seconds within those intervals, returning UTC. It advances interval-by-interval, not minute-by-minute, and preserves input seconds. Calendar exceptions replace regular hours. DST behavior is covered by a spring-forward test; ambiguous local shift boundaries need explicit operational calendar conventions.

The warning threshold uses ceil(target_minutes × warning_percent / 100) business minutes. Active tickets include waiting_for_customer. Resolution at or after the due instant is breached; monitoring records the deadline as the breach timestamp rather than scan time.

The scheduler checks active tickets every minute, in bounded batches. It locks/rechecks each ticket and records one warning or breach per cycle/level. A late scan emits breach only. Waiting does not pause time; reopening is excluded, so the current cycle is always 1.

## Import and notification reliability

CSV processing reads a private stream and processes rows individually with transactional row journals. Each row's ticket, audit/outbox, result, and counters commit together. Retried workers skip committed rows, and external references deduplicate across imports. Invalid rows are journaled separately. Per-import queue overlap locks prevent simultaneous processing. Stale pending and processing imports are requeued by tickets:recover-imports. Its row lock and updated timestamp prevent repeated recovery within the stale interval. tickets:retry-import explicitly resumes a failed import while retaining row journals. Late duplicate-job failure callbacks cannot overwrite a completed import. Worker failures are observable through Laravel failed jobs.

Outbox rows are committed with business changes and later published by tickets:dispatch-outbox. Publication failures leave rows pending. Concurrent dispatchers lock/recheck rows. Mail is at-least-once; deterministic event/recipient database-notification IDs prevent repeated in-app notifications. Queue workers for imports and notifications run independently. ticket.created acknowledges the customer even when the customer is the actor; other notifications exclude the initiating actor. List/detail ticket resources expose the immutable created_by ID and an eagerly loaded creator summary (ID/name only), distinct from the customer when an administrator creates a ticket on behalf of a customer.

## Production boundary

The implementation and MySQL concurrency tests establish correctness for the tested paths, not a performance certification. Shared Redis/cache, private shared file storage, proper SMTP, HTTPS, database backups, monitoring, and process supervision are deployment responsibilities. Shared-agent/category contention and parallel import chunks require representative benchmarking before horizontal-scale changes. Operational health metrics, scheduler heartbeats and structured log alerts are implemented; external alert delivery, Horizon, malware scanning and third-party integrations are not bundled. The React frontend is maintained in the linked companion repository, not under this Laravel resources directory.
