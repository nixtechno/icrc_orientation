<?php

namespace App\Http\Controllers;

use App\Models\{ProgrammeDay, TrainingFeedback};
use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrainingFeedbackController extends Controller
{
    public function store(Request $r)
    {
        abort_unless(TrainingFeedback::ready(), 404);
        // Only accepted once the form has opened today, for staff who have not answered yet.
        $opens = TrainingFeedback::opensFor($r->user());
        abort_unless($opens && now()->gte($opens), 422, 'Today\'s feedback is not open.');
        $programme = Access::programme();
        $day = TrainingFeedback::today($programme);

        $rules = ['comment' => 'nullable|string|max:3000'];
        foreach (array_keys(TrainingFeedback::STEPS) as $step) {
            $rules[$step.'_rating'] = 'required|integer|between:1,5';
        }
        $v = $r->validate($rules);

        DB::transaction(function () use ($v, $programme, $day) {
            $feedback = TrainingFeedback::firstOrCreate(['programme_day_id' => $day->id, 'user_id' => auth()->id()], [
                'programme_id' => $programme->id, ...collect($v)->except('comment')->all(), 'comment' => trim((string) ($v['comment'] ?? '')) ?: null,
            ]);
            if ($feedback->wasRecentlyCreated) Access::audit('training.feedback', (string) $day->id);
        });

        return back()->with('success', 'Thank you. Your feedback on today\'s training has been recorded.');
    }

    /** One programme day per page: the averages, then everyone who responded with their ratings and suggestions. */
    public function index(Request $r)
    {
        Access::manage('reports');
        abort_unless(TrainingFeedback::ready(), 404);
        $programme = Access::programme();
        $days = ProgrammeDay::whereHas('week', fn ($q) => $q->where('programme_id', $programme->id))->whereHas('feedback')->orderByDesc('date')->get();
        $day = $days->firstWhere('id', (int) $r->query('day')) ?? $days->first();

        return view('training-feedback', ['days' => $days, 'day' => $day, 'feedback' => $day ? TrainingFeedback::where('programme_day_id', $day->id)->with('user')->get()->sortBy(fn ($f) => mb_strtolower($f->user?->name ?? ''))->values() : collect(),
            'time' => TrainingFeedback::time($programme), 'staff' => Access::staff()->count()]);
    }
}
