<?php

namespace App\Http\Controllers;

use App\Models\{Activity, AssessmentFeedback};
use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssessmentFeedbackController extends Controller
{
    public function store(Request $r, Activity $activity)
    {
        abort_unless(AssessmentFeedback::ready(), 404);
        abort_unless($activity->programme_id === Access::programme()->id, 404);
        abort_unless(auth()->user()->role === 'staff' && $activity->kind === 'quiz' && $activity->published && $activity->participants()->where('users.id', auth()->id())->exists(), 403);
        // Feedback follows the assessment, so it opens only once an attempt has been submitted.
        abort_unless($activity->submissions()->where('user_id', auth()->id())->whereNotNull('submitted_at')->exists(), 422);

        $rules = ['comment' => 'nullable|string|max:3000'];
        foreach (array_keys(AssessmentFeedback::STEPS) as $step) {
            $rules[$step.'_rating'] = 'required|integer|between:1,5';
        }
        $v = $r->validate($rules);

        $created = DB::transaction(function () use ($activity, $v) {
            $feedback = AssessmentFeedback::firstOrCreate(['activity_id' => $activity->id, 'user_id' => auth()->id()], [
                ...collect($v)->except('comment')->all(), 'comment' => trim((string) ($v['comment'] ?? '')) ?: null,
            ]);
            if ($feedback->wasRecentlyCreated) Access::audit('assessment.feedback', (string) $activity->id);

            return $feedback->wasRecentlyCreated;
        });

        return redirect('/activities/'.$activity->getRouteKey())->with('success', $created ? 'Thank you. Your feedback has been recorded.' : 'You have already given feedback on this assessment.');
    }
}
