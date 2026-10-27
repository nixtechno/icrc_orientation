# Updated management requirements implementation

The October 2026 update supersedes earlier attendance and registration requirements. The timetable remains authoritative for programme dates, session wording and facilitator source labels. Public branding and the hero layout are unchanged by this update.

## Implemented phases

1. **Data and permissions:** Added encrypted profile details, private photographs, day policies, daily records, facilitator profiles/assignments, session Q&A, badge snapshots and participant import history. Existing accounts and timetable source history remain intact. Archived old session attendance without converting or discarding records. Administrative audit payloads redact profile details and secrets.
2. **Registration:** Added shared profile fields and validation, staff/supervisor manual entry and individual invitation, downloadable CSV/XLSX templates, row-level preview/validation, explicit valid-row confirmation and created/skipped/failed summaries. Invitations keep assigned roles, expire, cannot be reused and can be revoked/resend. Activation completes missing profile fields and records privacy acknowledgement.
3. **Daily attendance:** Removed operational session check-in routes and session attendance controls. Added explicit day windows and lateness thresholds, unique programme/day/staff records, signed daily QR check-in/out, audited manual correction, daily formulas and live 15-second polling with status/last update. Administrators have read-only historical attendance access for reconciliation.
4. **Q&A and learning:** Added session questions, permission-bound responses, publication/hiding with reasons, answered/unanswered state and relevant notifications. Facilitator profiles remain independent of accounts. Assessment assignment supports all staff, groups and individuals and optional week/session links; released results remain private to the participant.
5. **Badges and reporting:** Added editable labels/colour/dimensions, profile-based staff/supervisor/facilitator snapshots, individual/batch preview and PDF output, regeneration versions and explicit print confirmation. Added CSV/XLSX/PDF reporting using daily attendance and current assessment participation.
6. **Verification and operations:** Expanded the MySQL feature suite, exercised template round trips and PDF responses, checked a three-category badge sample, and rewrote README installation, workflow, queue, privacy, backup and retention instructions.

## Schema and relationships

`users.profile` is an encrypted text payload for personal and optional accommodation/emergency details. Official fields remain separately managed on users. User photos are private. Profile encryption depends on preserving APP_KEY.

`programme_days` owns attendance_required, cancelled, checkin_opens, checkin_closes and late_after. `daily_attendance` references programme, day and user, with a unique triple. The former attendance table is retained as `legacy_session_attendance`, excluded from all operational attendance metrics.

`facilitators` references a programme and optionally an authorised user; `facilitator_session` assigns sessions without rewriting the source facilitator labels. `session_questions` references a session and participant; `session_answers` references a question and authorised responder. Both have independent publication and moderation state.

`activities` can reference a programme week and a session. Existing assignment, question, attempt and answer tables continue to hold graded work.

`badges` references a programme and a user or facilitator profile and stores a minimal printable snapshot, category, generation/version/print state and generating administrator. No badge verification QR is included and badges grant no access.

`participant_imports` stores role, validated rows, row errors, creator, confirmation time and result summary. It is administrator-only and cannot be confirmed twice or by another account.

## Decisions still requiring ICRC configuration

Confirm attendance-required days and windows, the optional additional activation fields, privacy notice/retention policy, real SMTP sender, session source ambiguities and approved badge print dimensions. The system does not assume an induction-free day requires attendance. It does not introduce device binding, location verification, a badge verification service, certificates or an overall weighted completion formula.

## Verification commands

Run `php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml` against the separate disposable test database and `node --test tests/Frontend/programme.test.cjs` for existing landing interactions. Preview badge PDFs at actual size before production printing, especially for long names, photographs and non-default dimensions.
