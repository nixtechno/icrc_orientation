# Local demo and performance-test data

The demo runs separately from the real application, in `icrc_orientation_demo`, using `.env.demo` and a separate session cookie. Normal `db:seed` does not install demo accounts.

## Open the demo

URL: **http://127.0.0.1:8001/login**

All demo accounts initially use **`IcrcDemo!2026`**. This is a public, demo-only password. Never use it for real accounts.

| Account | Purpose |
| --- | --- |
| `admin@icrc-demo.test` | Full administration |
| `facilitator@icrc-demo.test` | All facilitator management permissions |
| `attendance@icrc-demo.test` | Attendance and reporting only |
| `assessments@icrc-demo.test` | Assessments and Q&A only |
| `staff0001@icrc-demo.test` | Active staff, ready to start a quiz |
| `staff0003@icrc-demo.test` | Staff with submitted work awaiting release |
| `staff0005@icrc-demo.test` | Staff with released results |
| `staff0010@icrc-demo.test` | Inactive account; sign-in is intentionally blocked |

Staff addresses run from `staff0001` to `staff0200` in the default dataset. Every tenth staff account is inactive. There are eight departments and four groups for filter/audience tests. All names, contact details and records are synthetic.

## Dataset installed on 29 October 2026

| Data | Count |
| --- | ---: |
| Staff | 200 (180 active, 20 inactive) |
| Management accounts | 4 |
| Programme weeks / days / source sessions | 6 / 30 / 187 |
| Synthetic daily attendance | 2,520 |
| Activities | 12 (quizzes, assignments, presentations) |
| Attempts / submissions | 680 |
| Learning resources | 18, with a downloadable demo PDF |
| Announcements | 12, including drafts, future, archived and targeted examples |
| Facilitator profiles | 12 |
| Session questions | 120, including published, hidden and awaiting moderation |
| Badges | 30 |

The programme timeline is shifted **inside the demo database** so it is already in progress. For the initial installation it runs from **12 October to 20 November 2026**. This creates meaningful past/present/future timetable and reporting views without altering the real programme. Rerunning the seeder preserves the established demo timeline.

Attendance includes present, late, absent, excused, checked-out and incomplete examples. No biometric credentials or physical location checks are fabricated; all verification flags remain false and each synthetic record is labelled. Real device enrolment still requires the normal secure registration process. Venue coordinates are deliberately not invented.

## Setup or restart

From the project directory:

```powershell
php scripts/setup-demo.php --staff=200
php artisan serve --env=demo --host=127.0.0.1 --port=8001
```

Keep the second command running while using the demo. The main application can keep running on port 8000. The setup script creates only the dedicated demo database, generates a separate application key, runs its migrations, and seeds it. It never runs `migrate:fresh` or deletes the main database. `.env.demo` is excluded from Git.

## Increase the volume

```powershell
php artisan --env=demo icrc:seed-demo --staff=1000
```

Supported range: **10–5,000 staff**. Increasing the count adds deterministic accounts and related data. Repeating the same count does not duplicate staff, activities, attempts or attendance. Lowering the count does not delete existing records. Existing account passwords and submitted attempts are not reset; curated demo announcements/resources are refreshed.

The seeder refuses a production environment, a database without the `_demo`/`_testing` suffix, or a database containing non-demo users. Demo seeding does not send email, dispatch notifications or disable attendance security. The demo environment uses log mail and synchronous queues.

## Suggested test flows

1. Admin: inspect dashboard counts, live attendance, action queues and pending reviews.
2. Staff management: filter by department/group/status, inspect inactive accounts and test invitations/imports.
3. Timetable: search, filter by week/date, review source changes and print.
4. Staff: open a published quiz, start an attempt, submit, then review and release as admin.
5. Assessment facilitator: grade work and moderate questions; confirm unrelated management modules are blocked.
6. Attendance: filter historical dates, inspect late/incomplete records and export daily/summary reports. Synthetic records display unverified device/location status intentionally.
7. Resources/announcements: test week/category filters, audience targeting, draft/publication/archive states and downloads.
8. Badges/reports: preview seeded badges and export CSV/XLSX/PDF reports.
9. Increase the dataset and repeat navigation, filters, imports and exports to compare responsiveness.

Inactive accounts have pending/expired/revoked invitation examples. For a local activation test, the initial pending invitation for `staff0010@icrc-demo.test` uses this URL (valid until its demo expiry):

`http://127.0.0.1:8001/invitation/demo-invitation-staff0010@icrc-demo.test`

## Validation and limits

Automated tests verify repeatability, account permissions, dashboard rendering, attendance summaries, PDF downloads, quiz availability and refusal to seed the main database. The demo's login and 13 key pages/exports were also checked through HTTP and returned 200.

Initial sequential local checks took approximately 0.2–0.7 seconds per HTML page and 1.1 seconds for the attendance CSV. These are development-machine observations, **not a concurrent-load capacity benchmark**. Large-scale concurrent load, real SMTP, physical biometrics/GPS and printers require separate tests.

Raw local HTTP timing results are in `storage/app/demo-http-checks.json`.
