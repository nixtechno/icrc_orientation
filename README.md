# ICRC Orientation Programme Management System

Laravel application for the ICRC Orientation Programme for the 2026 Recruited Staff, 2 November–11 December 2026. Programme times use Africa/Lagos. MySQL/MariaDB is the application database.

## Requirements

- PHP 8.2+, Composer 2, MySQL 8+ or MariaDB 10.4+.
- PHP extensions: PDO MySQL, mbstring, OpenSSL, fileinfo, DOM/XML, ZIP, GD, iconv and ctype.
- Laravel 12; Blade with local CSS and JavaScript. No Node build is required to serve the application.
- PhpSpreadsheet 5.10 provides XLSX templates/import/export. Dompdf 3.1 generates PDF badges and reports. BaconQrCode 3.0 produces signed daily attendance QR codes. Exact versions are in composer.lock.
- Node.js is optional for frontend interaction tests. SQLite is used only by the default isolated fast test suite, not the running application.

## Installation

1. Start XAMPP MySQL/MariaDB. Create an empty `icrc_orientation` database using utf8mb4/utf8mb4_unicode_ci.
2. Run `composer install`.
3. Copy `.env.example` to `.env` only on a new installation. Configure APP_URL, APP_KEY, MySQL credentials and mail settings. Do not replace an existing application key.
4. Run:

```powershell
php artisan key:generate
php artisan migrate --seed
php artisan icrc:create-admin
php artisan serve
```

The administrator command prompts privately for a chosen password. No production account or default password is seeded. New passwords require at least 12 characters with uppercase, lowercase and a number. Serve the `public` directory, never the project root. Use `php artisan storage:link` if uploading a replacement branding image through settings; supplied logo copies already work from public/brand.

The default local URL is http://127.0.0.1:8000. Account types share `/login`; permissions determine management access. Run `php artisan config:clear` after editing environment settings.

## Existing installations and updated requirements

Back up MySQL and private uploads before running `php artisan migrate`. The management update adds encrypted profiles, daily attendance, facilitator profiles, Q&A, badges and import history. It preserves accounts, original timetable source data, supplement reviews and existing learning work.

Old session attendance is retained in `legacy_session_attendance` for administrator-only reconciliation at `/attendance/legacy`. It never contributes to current calculations. Old session check-in URLs have been removed. Session attendance flags retained in historical source snapshots have no operational effect. No old records are silently merged into daily records; use audited daily corrections if a historical reconciliation is needed.

## Application flow and roles

1. Administrator creates the first account, configures registration/privacy policy and programme settings, and reviews timetable ambiguities.
2. Add staff and supervisors manually, by individual invitation, or by validated bulk import. Assign supervisor responsibilities and permissions explicitly.
3. Invitees complete missing profile information, acknowledge the configured privacy notice, set a password and activate their assigned account type. The invitation cannot change the role or overwrite preconfirmed employment fields.
4. Staff access their own profile, daily attendance, assigned assessments/work, released feedback and published programme content.
5. Supervisors manage only modules permitted to their role. The administrator has complete oversight, including unpublished content, profile management, badges, reports, policy settings and audit logs.
6. Authorised personnel configure applicable attendance days, publish activities/resources, moderate session Q&A, review work and release results.
7. Administrators generate and print badges; authorised reporting users export filtered CSV, XLSX or PDF reports.

The administrator dashboard links to all management areas and shows live daily attendance. Supervisors with `attendance` permission also receive the live view. Other permissions include staff, schedule, facilitators, resources, announcements, assessments, qa and reports. `qa_answer` is an explicit permission for an account linked to a facilitator's assigned sessions; it does not grant moderation. Facilitator profiles and badges do not create accounts or grant access.

## Registration, invitations and imports

Open **People & invitations** (`/manage/staff`). Manual forms collect personal/contact, employment, orientation and optional emergency details without requesting bank details or identity numbers. Profile photographs are private optional JPG/PNG uploads up to 2 MB. Accessibility and emergency details are stored inside an encrypted profile; protect APP_KEY and backups accordingly.

The individual-invitation form needs an assigned role, full name and email. Invitations expire after three days, are single use, and can be resent or revoked. Statuses are Pending, Sent, Activated, Expired and Revoked. Passwords and invitation tokens are not displayed in management lists or audit data.

For bulk registration, select Staff or Supervisor, download its CSV or XLSX template, and upload the completed file. Templates use identical profile fields: first_name, middle_name, surname, preferred_name, email, phone, staff_number, department, designation, resumption_date, duty_location, group_name. Use YYYY-MM-DD for dates. Partial employment/contact profiles are allowed; first name, surname and email are required on each imported row. Maximum 1,000 rows / 2 MB per upload.

Preview shows spreadsheet row numbers, invalid values, existing matches and within-file duplicates. Correct and re-upload, or explicitly confirm valid rows. Confirmation revalidates against current records, never overwrites accounts, and reports created/skipped/failed totals. An import can only be confirmed once by its creator. Optionally send invitations after creation. Bulk supervisor accounts start with no permissions; the administrator assigns those separately.

Registration policy at `/settings/registration` controls additional required activation fields, the programme-specific information label, and the privacy notice. Emergency contact fields are optional unless selected there. Staff may update permitted personal details and photos; official employment fields require an authorised manager. Activation may complete missing official fields but cannot replace already confirmed values.

## Programme timetable and source review

`php artisan icrc:import-timetable` is idempotent: it imports six weeks, 30 days and 187 original entries without overwriting edits. The exact original table text is stored in database/data/source-tables.json and each session's source metadata. Blank titles/facilitators remain unconfirmed. No venue or facilitator identity has been invented.

`php artisan icrc:import-supplement` stages 16 proposed updates from the Michael Uwaechie document. Review them at `/manage/supplement`. Importing never changes published entries automatically. Acceptance/rejection is audited, stale edits and overlapping additions are blocked, and both document sources remain available. See [supplement decisions](docs/timetable-supplement.md).

The 5 November 09:00–10:45 original duration discrepancy, 11 November `D 0 minutes` / `TBB`, and the supplementary 5 November afternoon split remain visible for review. Dinner is at 16:00 on 10 December without an invented end time; the 11 December induction-free day has no invented start time.

## Daily attendance

Attendance is once per applicable **programme day**. Sessions remain timetable, assessment and Q&A entities; they never determine the attendance denominator.

In `/attendance`, enable attendance explicitly for applicable days and set opening, closing and late-after times in Africa/Lagos. Days default to not required, including the induction-free day. Cancelled days are excluded. Staff use five-minute signed, authenticated QR links for daily check-in/check-out. Duplicate daily records are prevented by transactions and a database unique constraint on programme, programme day and staff. Check-out requires a prior check-in and cannot be repeated.

Authorised manual entry/corrections require a reason and audit the actor, time, before and after values. Timestamps must fall on the relevant day and cannot be in the future. Absence cannot be recorded before the check-in window closes. Missing records remain Pending; the system does not invent absence decisions.

The live supervisor table refreshes every 15 seconds and reports connection status and last update time. Date, department and status filters are available. Polling pauses in hidden tabs and resumes when visible.

Formula: **(Present days + Late days) / eligible elapsed days × 100**. Eligible days require attendance, are not cancelled, apply after enrolment, and have a closed check-in window. Future/non-required days do not count. Settings control whether Excused days remain in the denominator. Screens show numerator, denominator, percentage and pending decisions together.

QR codes alone do not establish physical presence. This update implements signed daily links and the required manual alternative; it does not add device binding, biometrics or geolocation.

## Assessments and Q&A

Assessments support multiple-choice, true/false, short-answer and long-answer questions, availability/deadlines, attempts, time limits, all-staff/group/individual assignment and optional week/session links. Objective responses can be scored automatically; written work remains pending manual review. Scores and feedback are visible to staff only after release. Assignments and presentations use the same private submission/review workflow.

Session Q&A at `/qa` is separate. Staff submit questions for moderation. Unpublished questions are visible only to their author and moderators; hidden questions are not shown to participants. Published, non-hidden questions/answers are available to enrolled participants. Supervisors with qa permission can answer and publish/hide content with an audited reason. Explicitly authorised facilitators may answer only their assigned sessions; facilitator responses await moderation. Published answers notify the question author. Answered status uses published, non-hidden answers.

## Badges and printing

Administrators use `/badges` to select existing staff, supervisors or facilitator profiles. Generation stores a profile snapshot and category, tracks versions and resets print status after regeneration. Missing photos use an initial. Category bands distinguish Staff, Supervisor and Facilitator. Badges contain no invitation or attendance tokens and no badge verification QR is included.

Configure programme label, footer, staff accent and badge dimensions (default 86 × 110 mm). Preview individual or batch badges (maximum 100), open the PDF and print at actual size / 100% scale. Each badge has its own PDF page at the configured dimensions. Confirm printing explicitly; a PDF download is not treated as proof that printing occurred. Regenerate after authorised profile changes. Check long names and chosen dimensions before a print run.

## Mail and queues

Local `.env` uses MAIL_MAILER=log: outgoing invitations/password resets appear in storage/logs/laravel.log rather than a real inbox. Protect those logs because local mail contains activation/reset links. Configure SMTP and the sender identity before inviting real participants. Invitations/password resets send through the configured mailer; programme notifications use the database queue:

```powershell
php artisan queue:work --tries=3
php artisan queue:failed
```

Use a supervised worker in deployment. Do not expose private uploads or local mail logs publicly.

## Testing

Create a separate empty `icrc_orientation_testing` database. Never point tests at application data.

```powershell
php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml
node --test tests/Frontend/programme.test.cjs
```

The MySQL test configuration uses local XAMPP credentials; adjust it for another environment. The default `php artisan test` runs the isolated in-memory SQLite suite. Feature coverage includes role restrictions, private records, invitation expiry/reuse/role immutability, CSV/XLSX template round trips and duplicates, daily calculations and QR uniqueness, audit records, live-view access, Q&A moderation, facilitator permissions, all badge categories and PDF output, assessment group assignment and reports. `php vendor/bin/pint app routes tests database` formats PHP sources.

## Deployment, backup and retention

- Point the web root to public and enable HTTPS. Set APP_ENV=production, APP_DEBUG=false, SESSION_SECURE_COOKIE=true and SESSION_ENCRYPT=true.
- Use a dedicated database user, strong environment secrets and protected APP_KEY. Never regenerate an established key; it encrypts profile information and sessions.
- Run composer install --no-dev --optimize-autoloader, php artisan migrate --force, config:cache and view:cache. Supervise the queue worker.
- Back up MySQL with a consistent mysqldump snapshot, private uploads, authorised branding and APP_KEY. Keep backups encrypted with restricted access. Test restoration into a separate database and verify profiles/photos, login and record counts before serving users.
- Existing SQLite copies are historical backups only; the application uses MySQL. See [MySQL setup](docs/mysql-setup.md).
- ICRC must approve the privacy wording, accommodation/emergency access, retention periods, deletion process, real contact/venue details, timetable ambiguities and attendance policy. No automated personal-data purging is enabled without an approved retention policy. Import previews, audit logs, badge snapshots, uploads and mail logs all require retention handling.

## Remaining operational decisions

Supply/confirm the privacy notice, real mail service, confirmed timetable changes, daily attendance windows, required profile fields, badge print dimensions and retention policy. Overall programme completion weighting and certificates are not implemented; progress measures are displayed separately without an invented overall percentage. Future programme accounts/enrolments are supported relationally, but the current management interface targets the ICRC 2026 programme.
