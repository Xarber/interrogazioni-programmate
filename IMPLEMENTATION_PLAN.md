# Modern UI and Encrypted Storage Implementation Plan

Status: **Approved. Implementation is in progress on `beta/modern-ui-secure-storage`.**

This plan is intentionally fixed. After approval, work will follow these steps and decisions. If a safety gate fails, work stops and the mismatch is reported rather than silently changing the plan or overwriting data.

## 1. Audited baseline

- Local repository: `/Users/xarber/Productivity/scuola-php`
- Current branch: `main`
- Baseline commit: `095601acfbfd131bf88450f2be3c1a8ba540bed2` (`fix event listeners`)
- `origin/main` and the remote repository's current `main` both point to that same commit.
- Existing local change: `.DS_Store` is modified. It belongs to the user and will not be edited, staged, reverted, or committed.
- Local PHP is not installed. PHP execution and extension checks will therefore be run on the server after the server preflight, while JavaScript/static checks can run locally.
- The server has deliberately not been contacted yet; SSH discovery starts only after this plan is approved.

### Active application files reviewed

- `interrogazioni.php`: all user-facing states and the client-side state machine.
- `manager.php`: profile selection, login lookup, all API scopes, subject scheduling, admin writes, profile import/export, notifications, and iCal.
- `assets/dash.js`: user/admin dashboards, account links, profile management, notification subscription/sending, subject/day/user editing, answer management, and data repair tools.
- `assets/app.css`: current application styling.
- `push-service-worker.js`: PWA cache, subscription context, push display, and notification-click routing to the selected subject.
- `app-push-server.js`: VAPID key handling and Web Push delivery.
- `assets/manifest.php`, `assets/manifest.json`, and `restoresession.php`: installed-app/session launch behavior.
- `README.md`, `package.json`, `.gitignore`, and the deprecated monolithic PHP version were also reviewed for compatibility and historical behavior.

### Existing behavior that must remain intact

1. First-run creation of the initial admin.
2. Manual login with a code/UID.
3. Direct login links containing `UID`, including saving the UID to browser storage and logging in automatically on later visits.
4. Default and named profiles, profile switching, and per-profile permissions/data.
5. The exact primary flow: **Choose subject -> Choose day/option -> Done**.
6. Full, locked, hidden, unavailable, already-answered, excluded, success, and failure states.
7. User appointment history and iCal/Google Calendar links.
8. The complete admin dashboard: users, admins/watchers, subjects, options/dates, availability, answers, moving/swapping/clearing/filling/fixing answers, profiles, ZIP import/export, copied reports, and copied invitation/login links.
9. Push notification subscribe/unsubscribe/send behavior and preservation of existing subscription records.
10. Clicking a notification must launch the app with the notification's selected subject.
11. PWA installation, manifest behavior, service-worker registration, and the existing offline cache/action-queue behavior.
12. Existing manual lock, hide, clear, notification, and copy-list controls remain available after automation is added.

## 2. Fixed implementation decisions

### Branch and repository safety

- All implementation commits will be made on a new branch named `beta/modern-ui-secure-storage`.
- `main` will not receive implementation commits.
- No force push, destructive Git reset, or deletion of the user's `.DS_Store` change will be performed.
- The plan file will be included in the feature branch.
- Commits will be created unsigned with `git commit --no-gpg-sign`; the user will re-sign them later with an interactive rebase.

### Storage design

- Replace runtime use of `JSON`, `JSON-*`, and subject JSON files with a small repository layer backed by SQLite.
- Use one encrypted registry database plus a separate encrypted SQLite database for every class. A class database contains its own users, admins, subjects, answers, permissions, histories, push subscriptions, priority settings, and automation state; no class can read or administer another class unless the same login code is explicitly a member of both.
- Encrypt every class payload and encrypted registry metadata before it reaches SQLite with PHP Sodium's XChaCha20-Poly1305 authenticated encryption.
- Use a random 32-byte master key stored outside the nginx document root. The key will never be committed to Git or stored in the database.
- Derive separate registry, lookup-index, and per-class encryption keys from the master key. Only keyed hashes, opaque class identifiers, nonces, ciphertext, schema metadata, and migration fingerprints will be visible in SQLite; class names, names, UIDs, subjects, answers, and push subscriptions will not be stored as plaintext.
- Bind ciphertext to its schema version and opaque class identifier as authenticated associated data so records cannot be silently swapped between classes or modified.
- Put the database and migration archive outside the nginx document root, with ownership limited to the PHP service account and restrictive permissions.
- Configure paths with environment variables so the code is testable without hard-coding the discovered server layout:
  - `SCUOLA_STORAGE_REGISTRY_DB`
  - `SCUOLA_CLASS_STORAGE_DIR`
  - `SCUOLA_STORAGE_KEY_FILE`
  - `SCUOLA_LEGACY_ARCHIVE_DIR`
  - `SCUOLA_ALLOW_CLASS_CREATION`
- Fail closed with a clear server-side error if the key, Sodium, SQLite, file permissions, or decryption are invalid. Never create a key inside the web root and never silently start with empty data when encrypted data cannot be read.
- Use SQLite transactions, a busy timeout, and application locks around registry/class creation and migrations. Scheduling and multi-record admin updates inside a class will be committed atomically to avoid partial subject/user updates.

### Legacy JSON auto-import

- At storage bootstrap, look for the default `JSON` folder and every `JSON-*` profile folder in the application root.
- Treat each legacy folder as one independent class: `JSON` becomes the default migrated class and each `JSON-<name>` folder becomes a named migrated class. Existing users who share a UID across folders become members of each corresponding class without merging the class data.
- If legacy folders exist, acquire the migration lock, validate every expected `.json` file, reject symlinks/unexpected entries/invalid JSON, calculate a fingerprint, and import all classes plus their keyed membership indexes transactionally.
- Legacy folders are authoritative when they reappear after an old-branch rollback. A new fingerprint is imported transactionally, allowing changes made while the old branch was active to return to the matching encrypted class database.
- After import, decrypt and compare every imported class with a canonicalized copy of its source, verify class/subject/user counts and membership indexes, record the fingerprint, then move the imported folders to the configured archive outside the document root.
- If validation, import, or verification fails, roll back the database transaction and leave the original JSON folders untouched.
- Add a command-line verification/export utility that can decrypt the secure store and recreate the old `JSON`/`JSON-*` layout for rollback.

### VAPID and other sensitive JSON

- Preserve the existing VAPID key pair exactly; changing it would invalidate current push subscriptions.
- Move `vapidkeys.json` outside the document root and make `app-push-server.js` read it from `SCUOLA_VAPID_KEY_FILE`.
- Keep the Node push server's fallback subscription persistence outside the document root as well.
- Do not put the VAPID private key into the browser, PHP API responses, logs, Git, or the SQLite database.

### Multi-class model and self-service class creation

- Promote the existing default/named profile concept into first-class, isolated classes while retaining backward-compatible `profile` parameters and legacy profile ZIPs during migration.
- The encrypted registry maintains only keyed login-code hashes mapped to opaque class identifiers. Entering a code performs a global membership lookup without storing or logging that code in plaintext:
  - one matching class logs in directly;
  - multiple matching classes show the existing chooser, relabeled as a class chooser;
  - no match shows the account-not-found state with a **Create a new class** action.
- Support a new `class` query parameter while continuing to accept old `profile` links. New invitation and notification links use the opaque class selector so a UID shared across multiple classes opens the correct class deterministically.
- Cache the login code and last selected class locally. A direct `UID` link still saves the code and logs in automatically; revisiting without parameters reuses the cached code/class only after the server confirms that membership still exists.
- Add **Create a new class** to both the initial login screen and account-not-found screen. The flow asks for the class name and first administrator's display name, then the server:
  - creates a cryptographically random opaque class ID and a separate class database;
  - derives a distinct class encryption key from the master key and class ID;
  - generates a cryptographically secure URL-safe login code with `random_bytes`;
  - creates the requester as that class's first admin;
  - returns and displays the code plus a copyable direct-login link once, caches the code, and enters the new class.
- Creating a class never grants access to any existing class and never copies existing users, admins, subjects, answers, notification subscriptions, or automation settings unless an authenticated admin explicitly uses the existing class/profile copy/import operation.
- Preserve admin-created users and invitation links inside each class. There is no open self-registration into an existing class; membership still requires a code created by that class's admin.
- Rename profile-oriented user-facing labels to **Classe/Classi**, while compatibility aliases keep old URLs, sessions, imports, and API clients working.
- Include the opaque class selector in service-worker state and every notification payload. Notification clicks restore `UID`, class, and selected subject so identically named subjects or shared UIDs cannot open the wrong class.
- Rebuild the keyed membership index atomically whenever users are added, removed, or imported. Deleting a class removes only that class database and its registry memberships after the existing admin confirmation flow.
- Protect anonymous class creation with strict input/size validation, atomic creation, and a conservative server-side request rate limit. The feature remains enabled by default as requested and can be disabled with `SCUOLA_ALLOW_CLASS_CREATION=0` in an emergency without affecting existing classes.

### Priority users and subject automation

- Add a per-class, per-user boolean `priority` flag. Admins can toggle it from each user row in the admin dashboard. Existing imported users default to non-priority unless explicitly changed.
- Priority is enforced only for a subject campaign whose priority window has been started. Subjects opened through the old manual controls without starting a campaign retain the current behavior where everyone can answer immediately.
- Add an automation panel to each subject with these persisted settings:
  - scheduled unlock date/time;
  - pre-unlock notification lead time, default **60 minutes**;
  - maximum priority-only window, default **180 minutes** after unlock;
  - unanswered-priority reminder interval, default **60 minutes**;
  - unanswered-regular-user reminder interval, default **180 minutes** after general access opens;
  - optional milestone notifications to the coordinating admin, enabled by an explicit toggle;
  - campaign timezone, defaulting to `Europe/Rome`.
- The admin who schedules or manually starts the campaign is recorded as its coordinator. The priority and eligible-user cohorts are snapshotted when the campaign starts so later user-flag changes cannot unpredictably change an active campaign.
- Watcher accounts and users already marked `Esclusi` are considered complete and never block a priority or all-users milestone.
- Define visibility and voting rules explicitly and enforce them in both the UI and `manager.php`, not only in browser controls:
  - **Hidden:** the subject is omitted from user lists, direct subject links do not reveal it, and no vote is accepted.
  - **Locked:** the subject is visible, including from a notification link, but no vote is accepted.
  - **Unlocked with active priority window:** snapshotted priority users may answer immediately; non-priority users see a clear waiting state and cannot submit through either the UI or a direct API request.
  - **General access:** starts as soon as all priority users have answered, or when the configured maximum priority window expires, whichever happens first.
- Hidden status overrides every campaign state. A hidden subject sends no campaign notifications and accepts no votes; a due automation pauses and surfaces an admin warning until the subject is made visible and explicitly resumed.
- If a campaign has no eligible priority users, general access opens immediately at unlock. Missing push subscriptions never block access transitions or completion; they are reported in dashboard delivery status.
- Campaign event sequence:
  1. The subject remains locked while the admin clears answers, defines dates/options, sets availability, and schedules the unlock.
  2. At the configured lead time, send one pre-unlock notification to eligible users with the planned opening time.
  3. At unlock time, unlock the subject, snapshot the cohorts, and notify eligible users with wording that accurately distinguishes priority access from the waiting period for everyone else.
  4. While priority-only access is active, remind only unanswered priority users at the priority interval.
  5. When all priority users finish, open general access early and notify unanswered non-priority users. If they do not finish first, open general access automatically when the maximum priority window expires and send the same notification.
  6. During general access, remind unanswered priority users at the priority interval and unanswered non-priority users at the regular interval.
  7. When all snapshotted eligible users are complete, stop reminders, mark the campaign complete, and leave the existing copy-ready answer list available. Do not auto-lock or alter answers.
  8. If coordinator milestone notifications are enabled, notify the coordinator once when priority users are complete/general access opens and once when all users are complete. If that admin has no push subscription, show the milestone state in the dashboard without treating it as an error.
- Clearing answers for a subject resets its previous campaign, reminder timestamps, completion markers, and queued campaign notifications. Re-locking pauses voting; it does not erase answers unless the admin separately uses the existing clear action.
- Use stable event IDs and an encrypted notification outbox so the worker can retry failures without repeatedly sending the same milestone. Reminder and delivery state remain inside the encrypted class payload.
- Add a PHP command-line automation worker, protected by a single-run lock and invoked once per minute by a dedicated systemd timer. It evaluates due unlocks, priority-window expiry, reminders, and completion milestones using an injectable clock and notification gateway for deterministic tests.
- The automation worker calls the existing local push service and retains notification payloads containing the subject, so clicking an automated notification opens that selected subject exactly like current manual notifications.

### UI direction

- Keep the current screens, state IDs, calls, and flow order; no framework rewrite and no change to the subject/day/done information architecture.
- Modernize the visual system with scoped CSS variables, a warm dark gradient, cleaner translucent cards, clearer typography and hierarchy, modern inputs/selects/buttons, compact status badges, and consistent dashboard surfaces.
- Add a non-interactive three-step progress indicator to the booking screens: **Materia**, **Giorno**, **Fatto**. It reflects the existing state machine and does not add or remove a step.
- Keep Italian UI copy and existing actions, while fixing only obvious presentation typos where behavior is unaffected.
- Improve mobile sizing, safe-area spacing, keyboard focus, contrast, disabled states, touch targets, reduced-motion support, and scroll behavior.
- Remove the global `transition: all 1s` behavior and use targeted short transitions so the interface feels responsive and does not interfere with hiding/showing screens.
- Harmonize the dynamically generated user/admin dashboards through shared styles without changing their operations.
- Add a clear priority badge/toggle on admin user rows and an automation/status panel on subject screens. User-facing priority waiting, locked, scheduled, and general-access states must explain what is happening without changing the main subject -> day/option -> done flow.
- Use system fonts and existing local imagery so the PWA remains self-contained/offline-friendly.
- Bump the service-worker cache name once so deployed clients receive the new CSS/JavaScript instead of stale cached assets.

## 3. Implementation sequence after approval

### Phase A — Read-only server discovery and synchronization gate

1. Connect with `ssh root@100.94.102.3`.
2. Inspect enabled nginx site configurations and resolve the `scuola` host's exact document root; do not guess the directory.
3. Record the nginx config path, document root, PHP-FPM service/user, Node push service/process definition, current working directory, PHP version, and availability of Sodium, PDO SQLite/SQLite3, Zip, and required Node dependencies.
4. Inspect the deployed Git repository if present: remote, branch, commit, status, ignored runtime files, and submodule state.
5. Compare all deployed tracked application files with local/origin `main` at baseline commit `095601a`, excluding expected runtime data and ignored secrets.
6. If the server has tracked-file drift, an unexpected commit, or uncommitted application changes, make a read-only diff/report and stop for the user. Do not overwrite or auto-merge server changes.
7. If the server matches `main`, continue. Runtime JSON differences are expected and will be handled only by the backup/migration phases.

### Phase B — Create the feature branch and implementation

1. Create `beta/modern-ui-secure-storage` from the verified baseline.
2. Add a storage module with:
   - environment/config validation;
   - key loading and key derivation;
   - SQLite schema setup;
   - authenticated encryption/decryption;
   - global keyed membership lookup;
   - class list/read/create/rename/copy/delete operations;
   - one independently keyed encrypted SQLite database per class;
   - subject/user read and transactional mutation helpers;
   - legacy scan/import/verify/archive logic;
   - secure legacy export for rollback.
3. Refactor `manager.php` to use only that storage API while keeping existing request parameters, response shapes, section names, authorization checks, backward-compatible profile semantics, ZIP format, iCal output, and notification API contract.
4. Ensure schedule submission updates subject availability/answers and user history in one database transaction.
5. Add server-side hidden, locked, and priority-window authorization checks to the schedule endpoint so crafted requests cannot bypass campaign rules.
6. Add global code lookup, class selection, anonymous class creation, cryptographically secure server-generated login codes, class-aware invitation/notification links, and backward-compatible profile aliases.
7. Add the admin priority toggle, subject automation controls/status, priority-wait user state, and milestone state without removing manual controls.
8. Add the idempotent automation worker, encrypted outbox, injectable clock/notifier, and systemd service/timer templates.
9. Adapt profile ZIP download/upload to export/import the same legacy-compatible JSON structure at the class boundary, while never using plaintext JSON as live storage. Priority and automation fields are included so round trips remain complete; older imports receive safe defaults.
10. Update `app-push-server.js` to load the preserved VAPID key and optional subscription persistence from configured paths outside the web root.
11. Update `interrogazioni.php`, `assets/app.css`, and narrowly necessary dashboard styling for the modern UI and three-step visual indicator without changing the state-machine order.
12. Preserve login-link caching and make notification routing class-aware; isolate/refactor small URL helpers as needed to make them directly testable.
13. Bump the service-worker cache version and retain notification-click construction of `UID`, class, and `subject`.
14. Update `.gitignore` and `README.md` with secure storage, required PHP extensions, legacy migration, multi-class creation/selection, priority workflow, automation timer, backup, deployment, and rollback instructions. No secret or real data will enter Git.

### Phase C — Automated and static verification before deployment

1. Run JavaScript syntax checks for `assets/dash.js`, `push-service-worker.js`, and `app-push-server.js`.
2. Run PHP lint for every PHP file using the verified server PHP runtime before changing the live checkout.
3. Run storage tests against temporary synthetic fixtures, never production data:
   - default and named-profile import as isolated classes;
   - exact users/subjects/answers/push-subscription round trip;
   - ciphertext contains none of the synthetic names, UIDs, subjects, or push endpoints;
   - wrong-key and tamper failures;
   - idempotent migration fingerprint handling;
   - re-import after simulated rollback changes;
   - create/copy/rename/delete class and atomic keyed membership-index maintenance;
   - legacy export and re-import;
   - transaction rollback on invalid JSON or interrupted update.
4. Run priority/automation tests with a fake clock and fake notification gateway:
   - legacy users default to non-priority and priority flags survive encrypted storage plus profile ZIP round trips;
   - hidden subjects are neither listed nor directly votable;
   - locked subjects are visible but not votable;
   - priority users can answer immediately after campaign unlock while direct and UI submissions from regular users are rejected;
   - general access opens immediately when all priority users finish, or exactly when the configured delay expires;
   - priority reminders occur more frequently than regular reminders and stop for users who answer;
   - pre-unlock, unlock, general-access, priority-complete, and all-complete events are emitted once, are retry-safe, and retain the selected subject in notification data;
   - watchers/excluded users do not block milestones;
   - clearing resets campaign state, while re-locking alone preserves answers;
   - completion leaves the existing answer list unchanged and copyable.
5. Run multi-class tests: a code in zero/one/multiple classes, anonymous class creation, secure server-generated code, first-admin permissions, class isolation, old `profile` links, new `class` links, cached class selection, cross-class UID reuse, class-aware notification clicks, class deletion, rate limiting, and membership-index repair.
6. Run API smoke tests with synthetic data for every `manager.php` scope: page load, first-class creation, users, subjects, scheduling, priority gating, automation configuration/status, class/profile management, ZIP import/export, iCal, and notification proxy behavior with a stubbed local push endpoint.
7. Verify no plaintext fixture secret appears in the registry database, class databases, WAL files, temporary files, logs, or web root.
8. Inspect the final diff to ensure no existing feature path was removed and no unrelated `.DS_Store` change was included.

### Phase D — Commit and publish the test branch

1. Commit only reviewed implementation, tests, documentation, and this plan on `beta/modern-ui-secure-storage`, using `--no-gpg-sign` for every commit.
2. Push the new branch to `origin` without modifying `main`.
3. Record the branch commit hash for deployment and rollback documentation.

### Phase E — Production-data backup before branch switch

1. On the server, briefly prevent concurrent writes during the final backup/switch window (using the existing service arrangement and the shortest practical maintenance interval).
2. Create a root-owned, timestamped backup outside the web root containing:
   - every `JSON` and `JSON-*` directory with permissions preserved;
   - the current VAPID key file;
   - any legacy subscription file;
   - the enabled nginx site configuration;
   - the PHP/Node service configuration relevant to this app and any installed automation timer configuration;
   - the deployed commit/status/diff metadata.
3. Generate SHA-256 checksums and verify the archive can be listed/read before continuing.
4. Create a second immediately usable rollback copy of the legacy JSON layout outside the web root, not only a compressed archive.
5. Install an external, root-only rollback helper next to the backup. It restores the JSON directories and VAPID key to their original paths with original ownership/permissions, then verifies their checksums. This helper remains available even after Git branches are switched.
6. Add and test an nginx deny rule for direct HTTP access to `JSON`, `JSON-*`, `vapidkeys.json`, and `subscriptions.json`. Server-side PHP/Node file access remains unaffected, so this rule is also safe after rollback to `main`.

### Phase F — Configure secure storage and switch the server to the branch

1. Install only missing required PHP extensions discovered in Phase A: Sodium and SQLite support. If package installation would require unrelated system changes, stop and report.
2. Create the external key directory, data directory, legacy archive directory, and a random 32-byte master key with restrictive ownership/permissions for the PHP service account.
3. Configure PHP-FPM/application environment variables for the database, key, and archive paths; configure the Node service with the external VAPID/subscription paths; configure the automation worker with the same storage paths and existing local push endpoint.
4. Preserve the existing VAPID key at its new external path before restarting the push service.
5. Fetch the published branch on the server and switch the verified deployment checkout from `main` to `beta/modern-ui-secure-storage`.
6. Run PHP lint and the synthetic migration/storage test suite once more in the deployed environment.
7. Start the application. Its bootstrap performs the required automatic legacy JSON import.
8. Verify import counts and cryptographic round-trip against the source JSON, then confirm the source directories were archived outside the web root. Do not remove the root-owned backups.
9. Install and enable the dedicated once-per-minute systemd automation timer only after its service definition, environment, lock, dry-run, and synthetic tests pass.
10. Reload only the affected nginx/PHP/Node services after their configuration tests pass.

### Phase G — Live smoke and visual testing

1. Test with a non-destructive existing account and avoid sending a broadcast to real users.
2. Verify direct login link behavior in a clean browser context:
   - open `?UID=<code>`;
   - confirm automatic login;
   - confirm the UID is cached;
   - revisit without the query parameter and confirm automatic login still works.
3. Verify an unknown/empty code can create a new isolated class, receives a server-generated admin code/link, caches it, and enters that class; verify the new admin cannot see any migrated class.
4. Verify a code in one class logs in directly, a code shared by multiple classes shows the class chooser, old `profile` links still resolve, and class-aware notification/invitation links select the intended class.
5. Verify the primary flow in order: subject -> available day/option -> confirmation. Use a controlled test class so production availability is not consumed unintentionally.
6. Verify class switching, already-answered, excluded, hidden/locked/no-days, and unavailable states.
7. In a controlled test class, flag priority and regular users; schedule a short test campaign with accelerated intervals; verify the pre-alert, unlock, priority-only rejection, early/timeout general opening, differentiated reminders, and completion milestones without contacting production users.
8. Verify optional coordinator alerts for priority-complete and all-complete, including the no-subscription dashboard fallback.
9. Verify the user dashboard, admin dashboard, priority toggle, automation/status panel, account invitation link, class operations, copied completion report, iCal, and legacy-compatible class ZIP round trip.
10. Verify push subscription records survived migration and the Node service is using the unchanged VAPID public key.
11. Exercise a controlled automated notification and confirm clicking it opens the correct path with the cached login UID, intended class, and selected `subject` parameter. Do not send to all production users.
12. Test desktop and mobile-width layouts, keyboard navigation, focus/disabled states, overflow, PWA manifest, service-worker update, and cached-asset refresh.
13. Confirm direct requests for all old JSON/VAPID/subscription paths return 404/denied while the app remains functional.
14. Confirm server and automation-worker logs show no secrets, cross-class data, duplicate event delivery, decryption failures, migration retries, PHP warnings, or Node push errors.

## 4. Rollback procedure

Rollback remains available throughout testing:

1. Stop writes briefly.
2. Switch the deployed checkout back to the previously recorded `main` commit.
3. Run the external root-only rollback helper to restore the backed-up `JSON`/`JSON-*` folders and original VAPID key to the paths expected by `main`.
4. Verify restored checksums, ownership, and JSON validity.
5. Keep the nginx deny rule active; it blocks public downloads but does not stop old PHP code from reading the files.
6. Disable the feature-branch automation timer, restore the previous PHP/Node service configuration if needed, and restart only affected services.
7. Smoke-test direct login, subject/day scheduling, admin data, and notifications on `main`.
8. Keep the encrypted database and original backup untouched for forensic comparison or a later retry.

If the old branch receives changes during rollback and the feature branch is tested again, its automatic importer will detect the restored legacy folders as a new fingerprint, back them up, import them transactionally as authoritative data, verify them, and archive them again.

## 5. Completion criteria

Work is complete only when all of the following are true:

- The server's original deployment was proven to match `main` before branch switching.
- All implementation exists only on `beta/modern-ui-secure-storage`, with unsigned commits ready for the user's later re-signing rebase.
- The subject -> day/option -> done flow is unchanged and visually modernized.
- Every listed existing feature passes regression checks.
- Direct login links still cache the UID and log in automatically.
- Unknown users can create a new isolated class, receive a secure server-generated admin code, and administer only that class.
- One login code can resolve across zero, one, or multiple classes without exposing or mixing class data.
- Legacy profiles migrate into separate class databases and old profile links/imports remain compatible.
- Notification clicks open the selected subject with the correct user and class context.
- Admins can flag priority users, configure/schedule a subject campaign, and still use every existing manual control.
- Hidden subjects cannot be discovered or voted through direct requests; locked subjects remain visible but reject votes.
- During an active campaign, only priority users can answer first; regular users open as soon as priority users finish or the configured delay expires.
- Pre-unlock, unlock, differentiated reminders, general-access, and optional coordinator milestone notifications are idempotent and stop when no longer applicable.
- Campaign completion leaves an accurate existing-format list ready to copy and send.
- Production registry and per-class data are encrypted at rest outside the nginx document root.
- No production name, UID, subject, answer, or push subscription is plaintext in the live database or web root.
- The original VAPID key is preserved and existing subscriptions remain usable.
- Automatic legacy JSON import is validated, transactional, repeatable after rollback, and archives source files only after verification.
- A verified, checksum-protected JSON/VAPID backup and external rollback helper exist outside the web root.
- The old `main` branch can be restored and operated using that backup without losing data.
