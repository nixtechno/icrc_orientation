<?php

namespace App\Http\Controllers;

use App\Services\{Access, AttendanceService, AttendanceSync, BadgeService};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * One-off maintenance jobs administrators run from Programme settings instead of the server command line:
 * attendance days, sign-ins from badge print times, badges printed without being recorded, collected badges, the daily feedback time and database updates.
 */
class DataToolsController extends Controller
{
    /** Figures for the Data tools tab, showing what each job would change before it runs. */
    public static function overview(Request $r): array
    {
        $service = app(AttendanceService::class);
        $days = $service->days()->orderBy('date')->get();
        $date = $r->query('signin_date') ?: now('Africa/Lagos')->toDateString();
        $day = $days->first(fn ($d) => $d->date->toDateString() === $date) ?? $days->first();

        return [
            'venue' => AttendanceSync::venue(),
            'days' => $days,
            'notAllDay' => $days->where('cancelled', false)->filter(fn ($d) => ! $d->attendance_required || substr((string) $d->checkin_opens, 0, 5) !== '00:00' || substr((string) $d->checkout_closes, 0, 5) !== '23:59' || $d->late_after)->count(),
            'signinDay' => $day,
            'signins' => $day ? $service->signInFromBadgePrints($day, false) : null,
            'signedOutOnly' => $day ? $service->signedOutOnly($day) : collect(),
            'uncollected' => app(BadgeService::class)->markPrintedCollected(false),
            'unrecorded' => app(BadgeService::class)->unrecordedPrints(),
            'pending' => self::pendingMigrations(),
            'feedbackTime' => \App\Models\TrainingFeedback::ready() ? \App\Models\TrainingFeedback::time(Access::programme()) : null,
        ];
    }

    /** @return list<string> database updates that have not been applied yet */
    public static function pendingMigrations(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        }

        return array_values(array_diff(array_keys($migrator->getMigrationFiles([database_path('migrations')])), $migrator->getRepository()->getRan()));
    }

    public function run(Request $r, string $job)
    {
        abort_unless($r->user()->role === 'admin', 403);
        Access::enrolled();
        // Day settings, attendance and badge state belong to the live site; venues copy them on the next sync.
        abort_if($job !== 'database' && AttendanceSync::venue(), 422, 'Run this on the live site. The venue copies the result on its next sync.');
        $back = redirect('/settings?tab=tools');

        return match ($job) {
            'attendance-days' => $back->with('success', 'Attendance is now taken all day, with no late time, on '.app(AttendanceService::class)->takeAttendanceAllDay().' programme days.'),
            'signins' => $this->signIns($r, $back),
            'signed-out-only' => $this->signedOutOnly($r),
            'badges-printed' => $back->with('success', app(BadgeService::class)->recordPrints(array_map('intval', $r->validate(['badges' => 'required|array|min:1', 'badges.*' => 'integer'])['badges'])).' badges are now marked printed and collected.'),
            'badges-collected' =>$back->with('success', app(BadgeService::class)->markPrintedCollected().' printed badges are now marked as collected at their print time.'),
            'feedback-time' => $this->feedbackTime($r, $back),
            'database' => $this->database($back),
            default => abort(404),
        };
    }

    /** When the daily training feedback opens on the portal, in programme time. */
    private function feedbackTime(Request $r, $back)
    {
        $time = $r->validate(['time' => 'required|date_format:H:i'])['time'];
        $programme = Access::programme();
        $before = \App\Models\TrainingFeedback::time($programme);
        $programme->update(['settings' => [...($programme->settings ?? []), 'feedback_time' => $time]]);
        Access::audit('training.feedback_time', (string) $programme->id, ['time' => $before], ['time' => $time]);

        return $back->with('success', 'Daily training feedback now opens at '.$time.'.');
    }

    private function signIns(Request $r, $back)
    {
        $date = $r->validate(['date' => 'required|date_format:Y-m-d'])['date'];
        $day = app(AttendanceService::class)->days()->whereDate('date', $date)->first();
        abort_unless($day, 422, 'Choose a programme day.');
        $counts = app(AttendanceService::class)->signInFromBadgePrints($day);
        Access::audit('attendance.signin_from_badges', (string) $day->id, null, $counts);

        return redirect('/settings?tab=tools&signin_date='.$date)->with('success', $day->date->format('l j F').': '.$counts['updated'].' records got their sign-in from the badge print time and '.$counts['created'].' records were added for staff with a printed badge and no scan.');
    }

    private function signedOutOnly(Request $r)
    {
        $v = $r->validate(['date' => 'required|date_format:Y-m-d', 'time' => 'required|date_format:H:i', 'records' => 'required|array|min:1', 'records.*' => 'integer']);
        $day = app(AttendanceService::class)->days()->whereDate('date', $v['date'])->first();
        abort_unless($day, 422, 'Choose a programme day.');
        $count = app(AttendanceService::class)->signInSignedOutOnly($day, array_map('intval', $v['records']), Carbon::parse($v['date'].' '.$v['time'], 'Africa/Lagos'));

        return redirect('/settings?tab=tools&signin_date='.$v['date'])->with('success', $day->date->format('l j F').': '.$count.' '.($count === 1 ? 'person who only signed out now has' : 'people who only signed out now have').' a sign-in.');
    }

    private function database($back)
    {
        $pending = self::pendingMigrations();
        if (! $pending) {
            return $back->with('success', 'The database is already up to date.');
        }
        Artisan::call('migrate', ['--force' => true]);
        Access::audit('database.migrate', 'migrations', null, ['applied' => $pending]);

        return $back->with('success', 'Database updated: '.count($pending).' '.(count($pending) === 1 ? 'update' : 'updates').' applied.');
    }
}
