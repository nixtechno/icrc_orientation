<?php

namespace App\Http\Controllers;

use App\Models\ProgrammeDay;
use App\Models\ProgrammeSession;
use App\Models\TimetableProposal;
use App\Notifications\ProgrammeUpdate;
use App\Services\Access;
use App\Services\SupplementImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplementController extends Controller
{
    public function index()
    {
        Access::manage('schedule');
        $items = TimetableProposal::with('session', 'day')->whereHas('day.week', fn ($q) => $q->where('programme_id', Access::programme()->id))->orderBy('programme_day_id')->orderBy('id')->get();

        return view('supplement', compact('items'));
    }

    public function review(Request $request, TimetableProposal $proposal)
    {
        Access::manage('schedule');
        abort_unless($proposal->day->week->programme_id === Access::programme()->id, 404);
        $values = $request->validate(['decision' => 'required|in:accept,reject', 'review_note' => 'required|string|max:4000']);
        DB::transaction(function () use ($proposal, $values) {
            $p = TimetableProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->status === 'pending', 409, 'This proposal has already been reviewed.');
            // Serialise reviews on the same programme day, including additions without a session row.
            ProgrammeDay::whereKey($p->programme_day_id)->lockForUpdate()->firstOrFail();
            if ($values['decision'] === 'accept') {
                $session = $p->session_id ? ProgrammeSession::whereKey($p->session_id)->lockForUpdate()->firstOrFail() : null;
                abort_if($session && SupplementImporter::snapshot($session) !== $p->baseline, 409, 'This timetable entry has changed since import. Review and update it manually using the source comparison.');
                $overlap = ProgrammeSession::where('programme_day_id', $p->programme_day_id)->whereNotIn('status', ['cancelled', 'archived'])->where('starts_at', '<', $p->proposed['ends_at'])->where('ends_at', '>', $p->proposed['starts_at']);
                if ($session) {
                    $overlap->where('id', '!=', $session->id);
                }
                abort_if($overlap->exists(), 422, 'This proposal overlaps an existing entry. Review the 2–3 p.m. change first, or resolve the overlap manually.');
                $before = $session?->toArray();
                $source = $session?->source ?? ['cells' => $p->source['cells'], 'day' => $p->source['day']];
                $source['supplements'][] = [...$p->source, 'review_note' => $values['review_note'], 'reviewed_by' => auth()->id()];
                $warnings = array_values(array_unique([...($session?->warnings ?? []), ...$p->warnings]));
                $session ??= new ProgrammeSession(['programme_day_id' => $p->programme_day_id, 'source_key' => $p->source_key, 'attendance_required' => false, 'status' => 'published']);
                $session->fill([...$p->proposed, 'source' => $source, 'warnings' => $warnings, 'reviewed' => false])->save();
                Access::audit('supplement.apply', (string) $session->id, $before, $session->toArray());
                $p->session_id = $session->id;
            }
            $p->fill(['status' => $values['decision'] === 'accept' ? 'accepted' : 'rejected', 'review_note' => $values['review_note'], 'reviewed_by' => auth()->id(), 'reviewed_at' => now()])->save();
            Access::audit('supplement.review', (string) $p->id, null, $p->toArray());
        });
        if ($values['decision'] === 'accept') {
            foreach (Access::staff()->where('active', true)->get() as $user) {
                $user->notify(new ProgrammeUpdate('Timetable updated', $proposal->proposed['title'], url('/timetable'), Access::programme()->settings['email_notifications'] ?? false));
            }
        }

        return back()->with('success', 'Review recorded. Both document sources have been retained.');
    }
}
