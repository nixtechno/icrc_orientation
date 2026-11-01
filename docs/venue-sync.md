# Offline venue attendance

A venue server is a second copy of this application on a laptop at the venue. Badge scanning keeps working on it when the internet is down. Every two minutes it uploads its scans to the live site and downloads people, badges, attendance windows and phone sign-ins.

Phones always sign in on the live site; their device registration is tied to the live site's address. The venue server only handles badge scanning.

## On the live site

1. Deploy the code, back up the database and run `php artisan migrate --force`. The sync migration only adds columns. Attendance saves fail on code from this release until it has run, even if sync is never used.
2. Generate a token of at least 32 characters, for example `php -r "echo bin2hex(random_bytes(32));"`, and set `ATTENDANCE_SYNC_TOKEN` in `.env`. Leave `ATTENDANCE_SYNC_URL` empty: an empty URL is what makes a copy the live site.
3. Run `php artisan config:cache` (or `config:clear`). The endpoints under `/api/attendance-sync/` must be reachable over HTTPS.

## On the venue server

1. Install the application as in the README (PHP, Composer, MySQL/MariaDB, `composer install`) with its own empty database.
2. Copy `.env.example` to `.env`, run `php artisan key:generate`, then set:

   ```dotenv
   ATTENDANCE_SYNC_URL=https://your-live-site
   ATTENDANCE_SYNC_TOKEN=<the same token as the live site>
   ATTENDANCE_SYNC_ORIGIN=venue
   ATTENDANCE_SYNC_UPLOAD=true
   ```

3. Run `php artisan migrate --seed`, which creates the programme and timetable.
4. **Create the scanner operator accounts here.** People downloaded from the live site arrive without passwords and cannot sign in on the venue server. Run `php artisan icrc:create-admin` for each operator, using the same email as their live account so their scans are credited to them on the live site. Facilitator operators need the Attendance permission.
5. While online, run `php artisan attendance:sync` once. It should report the people, badges and attendance it merged. Open **Daily attendance → Scan with scanner** and check that the sync line shows *Online* with no clock warning.
6. Keep `php artisan schedule:work` running for the whole event; it syncs every two minutes. **Sync now** on the scanner page runs a sync immediately.

## Dry run: download only

Set `ATTENDANCE_SYNC_UPLOAD=false` on the venue server to download from the live site without ever uploading. The sync only reads from the live site, scans made on the venue server stay there, and the scanner page says *Uploads are switched off*. Set it back to `true` (and run `php artisan config:clear`) when the venue should start sending its scans.

## Each day

- Check the sync line before scanning starts. A clock warning means scan times and late/on-time results will be wrong; correct the laptop's clock.
- *Waiting* counts scans not yet confirmed by the live site. It should return to 0 within a few minutes of the internet coming back.
- *Problems* are records the live site could not place, usually someone who is not on the live site. Their reason shows on the record; fix the person on the live site and the scan uploads on the next run.
- A badge can be scanned out on the venue server even if the person signed in by phone while the venue was offline. The two are combined when the venue syncs.

## How records combine

Both copies apply the same rule, so they settle on the same result: a phone-verified time beats a badge scan; otherwise the earliest sign-in and the latest sign-out win. Late/on-time is recalculated from the winning sign-in using the live site's late threshold. Exact locations and device details stay on the copy that verified them.

## Security

The token lets its holder download the full staff list (names, emails, staff numbers and roles) and upload attendance. Keep the venue laptop and its `.env` secured, and change the token on both copies after the programme.
