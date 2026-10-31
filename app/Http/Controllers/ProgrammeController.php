<?php

namespace App\Http\Controllers;

use App\Models\ProgrammeSession;
use App\Notifications\ProgrammeUpdate;
use App\Services\Access;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProgrammeController extends Controller
{
    public function landing()
    {
        return view('landing', ['programme' => Access::programme()]);
    }

    public function dashboard()
    {
        $p = Access::programme();
        $u = auth()->user();
        $programmeSessions = ProgrammeSession::whereHas('day.week', fn ($q) => $q->where('programme_id', $p->id));
        $sessions = (clone $programmeSessions)->with('day')->where('status', 'published')->whereDate('starts_at', today($p->timezone))->orderBy('starts_at')->get();
        $next = (clone $programmeSessions)->where('status', 'published')->where('starts_at', '>', now())->orderBy('starts_at')->first();
        $summary = $u->role === 'staff' ? app(AttendanceService::class)->summary($u) : null;
        $staff = Access::staff();
        $stats = ['Participants' => $staff->count(), 'Active accounts' => (clone $staff)->where('active', true)->count(), 'Awaiting activation' => (clone $staff)->where('active', false)->count(), 'Entries to review' => (clone $programmeSessions)->where('reviewed', false)->where('warnings', '!=', '[]')->count()];

        $operations = [];
        if ($u->role !== 'staff') {
            if ($u->canManage('schedule')) {
                $operations[] = ['Timetable entries to review', '/timetable?review=1', $stats['Entries to review'], 'Resolve source warnings before confirming session details.'];
                $operations[] = ['Draft sessions', '/timetable', (clone $programmeSessions)->where('status', 'draft')->count(), 'Review and publish sessions when details are confirmed.'];
            }
            if ($u->canManage('staff')) {
                $operations[] = ['Accounts awaiting activation', '/manage/staff', $stats['Awaiting activation'], 'Follow up on staff invitations and account activation.'];
            }
            if ($u->canManage('assessments')) {
                $operations[] = ['Submissions awaiting grading', '/activities', \App\Models\Submission::whereHas('activity', fn ($q) => $q->where('programme_id', $p->id))->whereNotNull('submitted_at')->whereNull('score')->count(), 'Review submitted work that has no recorded score.'];
            }
            if ($u->canManage('qa')) {
                $operations[] = ['Questions awaiting moderation', '/qa', \App\Models\SessionQuestion::whereHas('session.day.week', fn ($q) => $q->where('programme_id', $p->id))->where('published', false)->where('hidden', false)->count(), 'Review questions before making them visible to participants.'];
            }
        }

        $attendanceTrend = $u->role !== 'staff' ? app(\App\Services\AttendanceTrend::class)->data() : [];
        return view('dashboard', compact('p', 'u', 'sessions', 'next', 'summary', 'stats', 'operations', 'attendanceTrend'));
    }

    public function schedule(Request $r)
    {
        $q = ProgrammeSession::with('day.week')->whereHas('day.week', fn ($q) => $q->where('programme_id', Access::programme()->id));
        $manager = $r->user()->canManage('schedule');
        if (! $manager) {
            $q->where('status', 'published');
        }if ($r->filled('q')) {
            $q->where(fn ($q) => $q->where('title', 'like', '%'.$r->q.'%')->orWhere('facilitator', 'like', '%'.$r->q.'%'));
        }if ($r->filled('date')) {
            $q->whereHas('day', fn ($q) => $q->whereDate('date', $r->date));
        }if ($r->boolean('review') && $manager) {
            $q->where('reviewed', false)->where('warnings', '!=', '[]');
        }$sessions = $q->orderBy('programme_day_id')->orderBy('id')->get();
        // Each week is a tab. ?week=N opens that tab; otherwise the current or next week with sessions opens.
        $weeks = $sessions->groupBy(fn ($s) => $s->day->week->number)->sortKeys();
        $today = now(Access::programme()->timezone)->toDateString();
        $selectedWeek = $weeks->has((int) $r->week) ? (int) $r->week
            : ($weeks->keys()->first(fn ($number) => $weeks[$number]->contains(fn ($s) => $s->day->date->toDateString() >= $today)) ?? $weeks->keys()->first());

        return view('schedule', compact('sessions', 'manager', 'weeks', 'selectedWeek'));
    }

    public function editSession(ProgrammeSession $session)
    {
        Access::manage('schedule');
        abort_unless($session->day->week->programme_id === Access::programme()->id, 404);

        return view('session', compact('session'));
    }

    public function saveSession(Request $r, ProgrammeSession $session)
    {
        Access::manage('schedule');
        abort_unless($session->day->week->programme_id === Access::programme()->id, 404);
        $v = $r->validate(['title' => 'nullable|string|max:4000', 'facilitator' => 'nullable|string|max:2000', 'venue' => 'nullable|string|max:255', 'type' => 'required|in:session,recap,break,lunch,presentation,dinner,free_day', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date|after:starts_at', 'status' => 'required|in:published,draft,cancelled,archived', 'reviewed' => 'nullable|boolean']);
        $v['reviewed'] = $r->boolean('reviewed');
        $before = $session->toArray();
        DB::transaction(function () use ($session, $v, $before) {
            $session->fill($v);
            $session->actual_minutes = $session->starts_at && $session->ends_at
                ? (int) $session->starts_at->diffInMinutes($session->ends_at)
                : null;
            $session->save();
            Access::audit('session.update', (string) $session->id, $before, $session->toArray());
        });
        if ($session->status === 'published') {
            foreach (Access::staff()->where('active', true)->get() as $u) {
                $u->notify(new ProgrammeUpdate('Timetable updated', $session->title ?? 'Timetable entry updated', url('/timetable'), Access::programme()->settings['email_notifications'] ?? false));
            }
        }

return redirect('/timetable')->with('success','Timetable updated; original source retained.');
    }
}
