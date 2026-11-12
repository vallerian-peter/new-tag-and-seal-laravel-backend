# Hiring and farm access

Vacancy selection, joining confirmation and employment activation are separate decisions. The employer sends joining details, the applicant replies, and the registered farm owner explicitly activates access. Confirmation and arrival alone never grant permissions. An employer may activate before physical arrival.

`career_onboardings` holds joining terms and responses independently of the immutable application snapshot. `farm_worker_assignments` retains employment history. `farm_access_grant_sources` records the rights supplied by each placement or independent manual grant; `farm_access_grants` is their effective union. Ending one placement clears its source, retains unrelated grants, and keeps an explicit empty denial when no rights remain.

Sending joining details locks the vacancy row before counting historical hires and occupied placements. Sent, confirmed and activated placements occupy a position. Refusal, cancellation, not joining and ended employment release it. Deadlines only flag overdue work. A reserve candidate must be selected explicitly. Historical Hired applications consume capacity but receive no new access; use Send joining details to complete their onboarding.

Manual entry links only a verified phone identity. Unknown phones produce pending invitations and no new login accounts. Verification links a pending invitation; the owner must still activate it. The service never changes account passwords or global roles. Farm ownership comes from the explicit farmer membership relationship, with unambiguous legacy role-to-profile relationships backfilled.

Owners select view/record duties. Finance, livestock management and record voiding are separate rights. An event definition's `access_module_code` is the stable duty used for authorization; it is included in configuration manifests. Set it when authoring an event that workers should use. Definitions without a module remain unavailable to scoped workers. Broad pre-existing grants remain independent legacy sources.

Dynamic event reads, reports, change feeds and submissions check the same modules. Submissions check permissions before idempotent replay and again under the farm lock before persistence. Legacy log synchronization checks current duties, original record ownership and destructive permissions. Clients cannot authorize a queued offline submission using an old role or cached permission.

## Deployment

1. Back up the database and deploy the additive migration `2026_11_04_000001_connect_hiring_to_farm_workers.php` and the subsequent permission-manifest migration and the additional-worker-duty mapping migration before enabling the new app. Local migration backups are saved under `storage/app/private`.
2. Deploy the shared hiring services and API routes, then the Flutter app. Ownership and legacy assignments are backfilled without sending SMS or activating historical Career hires.
3. Run Laravel's scheduler every minute (`php artisan schedule:run`) and a database queue worker (`php artisan queue:work database --tries=5 --timeout=45`). The queue connection must have its standard jobs/failed_jobs tables.
4. The scheduler's `hiring:dispatch-notifications` command claims durable outbox events. Business actions and in-app notifications commit together. SMS is sent afterward; failure never rolls back hiring. Sent events are skipped on retry. Inspect `sms_status`, `sms_attempts` and `sms_next_attempt_at` for delivery processing. A gateway acceptance is recorded as sent; it is not a handset delivery receipt.
5. The in-app inbox is available from Career workspaces and Farm workers. Joining notifications open the authorized placement. Owners can resend explicit reminders; duplicate reminder requests within an hour share one event.

The local rollout does not start a queue worker or send live SMS. Enable workers/scheduling in the target environment when ready to deliver notifications.

## Verification

`php artisan test` covers hiring, grant sources, account reuse, pending invitations, capacity, immutable snapshots, module visibility, revoked queued retries, historical migration and existing regressions. Flutter Career/FarmUser tests include English/Swahili phone layouts and explicit activation. The iOS simulator build passes. Local Android APK verification is blocked by insufficient disk space; retry `flutter build apk --debug` after freeing space.

The MySQL race test is `tests/Feature/HiringConcurrencyTest.php`. It runs only with MySQL, pcntl, and an isolated database named `tag_seal_hiring_concurrency_test_*`; it resets that database. The default SQLite suite skips this test. It verifies simultaneous offers, JSON-backed retry behavior and concurrent grant-source projection updates.
