# Application audit — 29 October 2026

## Outcome

The current automated regression suite and local HTTP smoke checks passed. One bulk-import defect was found and corrected: international alternative phone numbers beginning with + were incorrectly rejected by formula protection. Valid international phone numbers now import into both phone fields; formula checks remain in place for other values.

The application still needs the environment configuration listed below before live onboarding and venue attendance. Passing these checks is not a guarantee for every browser, input, physical device or production service.

## Bulk upload formats

Download fresh role-specific templates from People & invitations → Bulk upload. CSV and XLSX use the same columns for each role.

**Staff (12 columns)**

```csv
first_name,middle_name,surname,preferred_name,email,phone,staff_number,department,designation,resumption_date,duty_location,group_name
```

The removed Orientation information section was never included in the bulk template. Neither accommodation nor programme_information is requested in the current Staff template.

**Facilitator (10 columns)**

```csv
first_name,middle_name,surname,preferred_name,email,phone,alternative_phone,organisation,department,designation
```

organisation represents Organization; designation represents Title. The facilitator template excludes staff employment/orientation/emergency fields and includes all four newly requested facilitator contact fields. Imported accounts remain inactive until activation. The invitation fixes the recipient's email and role. CSV import persistence and CSV/XLSX template round trips passed, including international phone numbers, duplicates, invalid rows and confirmation.

## Verification results

| Check | Result |
| --- | --- |
| Backend regression suite | 82 passed; 1,116 assertions |
| Frontend interaction tests | 33 passed |
| PHP syntax | 97 files passed |
| JavaScript syntax | 15 files passed |
| Blade compilation | Passed |
| Whitespace/diff validation | Passed |
| Live demo permitted page/template requests | 38 returned HTTP 200 |
| Live Staff access restrictions | 3 returned expected HTTP 403 |
| Replacement characters in tested live responses | None found |
| Pending main application migrations | None |

Backend tests used the isolated icrc_orientation_testing database. Live checks used synthetic accounts at http://127.0.0.1:8001 and did not submit programme changes or send invitations. The tests use fake mail and Google responses.

Coverage includes authentication and password reset, activation and invitation expiry/reuse, email/role locks, Admin/Facilitator/Staff permissions, role-specific forms, CSV/XLSX imports, public slugs, timetable and supplement reviews, resources and announcements, assessments and file submissions, multiple-answer grading, duplicate submission protection, staff attendance summaries and dashboard graphs, device/GPS validation, replay/concurrency protection, Q&A moderation, reports and badges, settings and notifications. Frontend checks cover account menus, mobile navigation, search without full reloads, login token refresh, submission click protection and role-dependent fields.

## Main environment setup still required

Read-only configuration inspection found:

- Google reCAPTCHA: site key and secret are absent; verification is currently disabled in the local environment. Add RECAPTCHA_SITE_KEY and RECAPTCHA_SECRET_KEY, then clear or rebuild the configuration cache. Verification logic passed simulated success/failure checks; live Google verification has not been exercised.
- Email: the configured mail transport is log. This writes messages locally and does not establish inbox delivery. Configure and test the intended SMTP/provider transport before live invitations.
- Attendance: venue coordinates are absent and zero days are marked attendance-required. Configure the authorised venue, required days and sign-in/out windows before real attendance. The demo contains separate synthetic attendance records.
- Queue: the configured connection is database. A production queue worker must be running for queued notifications; its operation was not established by this audit.

Real device biometric/PIN prompts, venue GPS accuracy, HTTPS/origin deployment, inbox delivery, physical printing and large-scale load were not tested in this run.

## Evidence

- storage/app/final-audit-backend.txt
- storage/app/final-audit-junit.xml
- storage/app/final-audit-frontend.txt
- storage/app/final-audit-http.json
- storage/app/final-audit-syntax.json
- storage/app/final-audit-readiness.json

Repeat with php artisan test, node --test tests/Frontend/*.test.cjs, php artisan view:cache and git diff --check.