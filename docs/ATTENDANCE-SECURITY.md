# Secure daily attendance implementation

Implemented 27 October 2026 in the existing Laravel application, using the existing MariaDB daily_attendance table. The original authentication, roles, enrolments, timetable, historical session attendance, learning and assessment modules remain in place.

## What changed

- One daily attendance record per programme, staff member and day; existing database uniqueness retained. Server timestamps, staff snapshots, attendance date, punctuality, completed duration and separate in/out device and location verification fields added.
- Two permanent QR links: /attendance/sign-in and /attendance/sign-out. No identity, credential, token, signature or expiry in a QR. Scanning never creates attendance. Supervisors can download SVG, print the display or present it on screen.
- Mandatory WebAuthn enrollment with account-password confirmation, user presence and user verification. One active credential per user globally, enforced by a unique nullable active_user_id. Synced/backup-eligible credentials rejected at registration and every assertion. No password-only attendance, fingerprinting or cookie-based device authorization.
- Fresh browser location before requesting a challenge and again after device authentication. Backend performs all distance, accuracy and freshness checks. Location stored encrypted at rest using the application key; coordinates hidden from model serialization and excluded from reports and audit messages.
- Staff request replacements; attendance-authorized supervisors/admins decide with a reason. Approval revokes the old credential, consumes outstanding challenges and permits exactly one new enrollment. No self-approval and no attendance created by replacement.
- Manual attendance marking removed, as explicitly required by this update. Historical records retain their status and are not falsely labelled verified. Former expiring daily QR endpoints no longer authorize attendance.
- Existing 15-second live polling extended with eight metrics, date/department/name/status filters and verification statuses. Staff dashboard includes today, device details, history, duration and summary.
- Existing reporting extended with daily, individual, departmental, late, incomplete and programme-summary modes in CSV, XLSX and PDF. Attendance supervisors can export attendance without gaining access to other reports. Exact coordinates never exported. Detailed attendance PDFs use A3 landscape to accommodate verification columns.

## Database migration

New additive migration: database/migrations/2026_10_27_300000_secure_daily_attendance.php (applied to the local application database).

Existing tables extended:
- programme_days: checkout_opens, checkout_closes.
- daily_attendance: staff_name, staff_number_snapshot, attendance_date, punctuality_status, duration_seconds, signin_device_id, signout_device_id, separate device/location verification booleans and encrypted signin_location/signout_location.

New tables:
- attendance_devices: credential hash/ID, public key, counter, AAGUID, label, active-user unique key, registration/verification/revocation dates. Stores no biometric or PIN data.
- attendance_device_requests: requester, programme, reason, status, decision maker/reason/dates and consumed enrollment permission.
- attendance_challenges: single-use random challenge, user/programme/day/device/purpose, hashed session ID and expiry.
- attendance_audit_logs: staff, actor, event, result, reason, server timestamp, IP and user agent.

No attendance table recreated or reset. Rollback is destructive to the newly added security metadata and should not be used on live data as a routine deployment action.

## Routes and controllers

Existing DailyAttendanceController handles /attendance, /attendance/live, /attendance/legacy, day configuration and permanent QR display/download.

New AttendanceSecurityController:
- GET /attendance/sign-in, /attendance/sign-out, /attendance/device
- POST /attendance/verification/{register|in|out}/{options|verify}
- POST /attendance/verification-failure (allowlisted client failure reasons only)
- POST /attendance/device/replacement
- POST /attendance/device/replacement/{replacement}
- POST /attendance/security/settings
- GET /attendance/security/audit

QR management:
- GET /attendance/qr
- GET /attendance/qr/in.svg and /attendance/qr/out.svg
- Existing /manage/days/{day}/qr redirects to the permanent display.

Removed authorization paths: POST /manage/attendance and GET/POST /daily/checkin/{day}. They return 404. Staff credentials cannot authorize manager pages. All new attendance endpoints use existing authentication and ActiveAccount middleware; POST endpoints retain CSRF protection and verification endpoints have throttling. Existing login now respects the intended URL so an unauthenticated QR scan returns to its attendance page after login.

## Frontend

Updated resources/views/daily/index.blade.php, live.blade.php and qr.blade.php. Added verify.blade.php, audit.blade.php and public/attendance-security.js. Existing daily-attendance.js remains the live polling mechanism: no refresh while hidden, no overlapping fetches, retries and a connection indicator. Interface uses existing cards, forms, navigation and responsive table wrappers.

## WebAuthn verification

Dependency: lbuchs/webauthn 2.2.0 (Composer package and lock updated).

The browser is asked for a platform authenticator, internal transport and required user verification. The server verifies the exact configured origin (including port), RP ID hash, ceremony type, challenge, account, session binding, expiry, credential ownership, signature, user presence, user verification, backup flags and signature counter. A supplied userHandle must match the account's stable opaque handle. Cross-origin ceremonies are rejected. Nonzero counters must strictly increase; authenticators with permanent zero counters still require unique consumed challenges.

Challenges are consumed in a separate transaction before verification; failed cryptographic attempts cannot replay them. Final writes lock the staff row, programme policy, programme day and device, check current account/enrolment/revocation/eligibility/location again, and write attendance plus success audit atomically. Unique keys backstop application locking. Replacement serializes against the same staff lock.

Only public keys and credential metadata are stored. Device biometric/PIN verification happens locally. A credential registered to account A cannot authorize account B even when a valid signature is supplied.

Important assurance boundary: requesting a platform authenticator and rejecting signed BE/BS flags prevents compliant synchronized passkeys from being accepted. This does not certify a unique physical phone or make a software authenticator uncloneable. Attestation is 'none'; there is no manufacturer trust-store or managed-device identity attestation. Initial registration relies on the account password and session; supervised identity checks during rollout remain an organizational responsibility. Stronger physical-device assurance requires a separately designed managed-authenticator attestation policy. Do not advertise biometric verification as proof of one physical phone.

References: https://github.com/lbuchs/WebAuthn and https://www.w3.org/TR/webauthn-3/#sctn-credential-backup

## Location and time policy

Configure approved venue name, latitude/longitude, radius, maximum accepted accuracy and maximum location age on the attendance page. No real venue coordinates were guessed or installed. Haversine uses Earth's mean radius 6,371,000 metres. Acceptance requires distance + reported accuracy <= allowed radius. Missing, malformed, inaccurate, stale, future-dated or outside locations fail with a clear explanation. Maximum permitted future timestamp skew is five seconds; location age is configurable from 10 to 120 seconds. Client-supplied distance is ignored.

Browser GPS is spoofable and cannot guarantee physical presence. Location denial/unavailability/timeouts produce user messages and allowlisted failure audit events. Devices need location services and precise-location permission.

Configure programme starts/ends, explicitly required days, cancellation and all five daily thresholds. No weekend assumption and no guessed checkout times. Timezone is Africa/Lagos. Attendance remains unavailable until policy is configured. Missing elapsed attendance contributes to derived absence counts; future days do not. Original pending/historical statuses are preserved in storage. Existing approved excused-denominator policy is retained.

## Retention: approval pending

Location and audit retention periods can be configured, but automatic deletion is DISABLED. Automatic approval review rejected scheduled deletion of expired audit logs because it permanently erases audit history. No destructive retention workaround was installed. The administrator command `php artisan attendance:retention-report` is read-only. It does not delete records, coordinates, challenges or audit history.

The interface states this limitation. Approval has been requested to enable deletion of exact location details and security audit logs only after their configured periods, retaining core attendance records and verification outcomes. Until approved and implemented, do not claim that retention expiry is automatically enforced. Database backups also need an approved retention policy.

## Deployment configuration and rollout

1. Keep MySQL/MariaDB; no switch to SQLite. Back up the application database, then run `composer install --no-dev --optimize-autoloader` and `php artisan migrate --force` on deployment.
2. Select the permanent public HTTPS domain before enrollment and printing QR codes. Set APP_URL to that domain, ATTENDANCE_ORIGIN to the exact origin (scheme, host, optional port; no path/trailing slash) and ATTENDANCE_RP_ID to its hostname. Application should be served at the domain root. Changing the RP hostname requires credential re-enrollment and a domain change alters printed QR destinations.
3. Local attendance development is configured as ATTENDANCE_ORIGIN=http://localhost:8000 and ATTENDANCE_RP_ID=localhost. Use http://localhost:8000 for verification. HTTP 127.0.0.1 or a LAN IP is rejected by this strict WebAuthn configuration. A phone visiting localhost reaches that phone, not this PC; phone testing needs a reachable, trusted HTTPS domain.
4. Run `php artisan config:clear` after environment edits, then use normal production config caching. Set APP_DEBUG=false, secure/HTTP-only session cookies, correct proxy/TLS settings and a stable APP_KEY. Preserve that key securely because it encrypts stored locations. Keep server time synchronized.
5. As an attendance-authorized supervisor/admin, enter real venue coordinates, approved radius/accuracy/retention periods, programme dates and each applicable day's sign-in/late/sign-out windows. A configured day does not become a session attendance record.
6. Verify actual staff devices on the final domain before rollout. Devices that only provide synced passkeys will be rejected. Desktop localhost testing is not evidence that staff phones can enroll.
7. Review/approve retention automation before enabling any purge job. No purge is scheduled by this implementation.

No production staff enrollment, invented test accounts or fabricated attendance were added to the application database. Tests use the separate icrc_orientation_testing MariaDB database.

## Validation

Run the suite using:
`C:\xampp\php\php.exe vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml`

SecureAttendanceTest uses real generated P-256 keys, CBOR registration attestation and ES256 assertion signatures against the real WebAuthn verifier, not mocked successful authentication. Covers registration, synchronized credentials, missing device/password-only attempts, successful late and on-time attendance, checkout/duration, duplicate actions, wrong signature, absent UV, backup flags, wrong origin/RP/challenge/session/account, replay/expiry/counters, location denial/missing/outside/accuracy/staleness/future timestamps, policy changes between challenge and verification, revocation/replacement permissions, permanent QR, dashboard and export permissions/calculations, preservation and database uniqueness.

AttendanceConcurrencyTest starts two PHP worker processes against the separate testing database. Both submit the same real signed assertion at once. Exactly one returns 200, the other returns 422, and exactly one daily record is persisted. The worker hardcodes the testing database and does not use the application database.

The pre-enhancement manual/expiring-QR tests now assert those bypasses are unavailable. Other module tests remain intact. Blade compilation, route inventory and JavaScript syntax checks are also performed.

Physical-device biometric/PIN ceremonies, actual venue GPS performance, final-domain QR scanning and visual PDF/browser review require acceptance testing on the deployment devices. Automated server rendering and PDF response checks are not physical-hardware verification.

Final verification result: 41 tests passed, 394 assertions on MariaDB using the default phpunit.xml (also configured to the isolated icrc_orientation_testing database). JavaScript syntax checks and Blade compilation passed. A subsequent targeted attendance regression run covers the final programme-date-boundary changes. No physical-device or final-domain acceptance claim is made.
