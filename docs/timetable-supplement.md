# Michael Uwaechie timetable supplement

The additional document `ICRC_Induction_Programme_Michael_Uwaechie.docx` covers 2–5 November 2026 only. It is supplementary programme data, not application instructions. Its exact table text and document SHA-256 are stored in `database/data/michael-uwaechie-supplement.json`. The original six-week source remains unchanged in `database/data/source-tables.json`.

The supplement supplies twelve numbered PPP session titles and three expanded recap titles. It also tidies the registration wording, corrects the stated duration of the 5 November 09:00–10:45 slot from 30 to 105 minutes, and proposes an afternoon split on 5 November.

The afternoon split requires confirmation: Session 12 runs 14:00–15:00 but states 120 minutes. An additional presentations and exercises entry runs 15:00–16:00 with no stated duration. Both ranges calculate to 60 minutes; the supplied duration values are retained verbatim. No venue or additional facilitator identity is inferred.

## Import and review

Run `php artisan migrate` then `php artisan icrc:import-supplement`. Fresh installations also stage these proposals through `php artisan db:seed`. Importing is idempotent and never changes a published timetable entry. Unchanged rows, ignoring whitespace, are omitted from the change list.

An administrator or enrolled supervisor with the `schedule` permission opens **Programme & timetable → PPP supplement review** (`/manage/supplement`). Each change shows the current entry, proposed values, exact supplementary source, import baseline and warnings. Acceptance or rejection requires a review note and records the actor and audit history. Staff cannot access this management comparison.

Accept the 14:00–15:00 change before accepting the added 15:00–16:00 event if the programme owner confirms the split. The application blocks overlaps. It also blocks acceptance when someone has edited the target entry since import; use the entry editor to reconcile such changes manually, then reject the stale proposal with an explanation.

Accepted changes preserve original source cells, append the supplementary provenance, retain historical source warnings, and never change the separate daily attendance policy. Session attendance flags retained in original snapshots are historical only. Staff receive the existing queued timetable-change notification. Run `php artisan queue:work` to deliver notifications.

Weeks 2–6 and Friday 6 November are not addressed by this supplement. The 11 November `D 0 minutes` / `TBB` issue remains unresolved. An accepted correction does not erase the original 5 November duration discrepancy from history.

Run `php artisan test` for access, source fidelity, idempotence, stale-edit protection, overlap handling and the existing application tests.
