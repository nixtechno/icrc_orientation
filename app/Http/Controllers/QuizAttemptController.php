<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use App\Services\{Access, QuizAttemptService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuizAttemptController extends Controller
{
    private function authorizeAttempt(Submission $submission): void
    {
        abort_unless(auth()->user()->role === 'staff' && $submission->user_id === auth()->id(), 403);
        abort_unless($submission->activity->programme_id === Access::programme()->id, 404);
        abort_unless($submission->activity->kind === 'quiz' && $submission->fullscreen_started_at, 422);
    }

    private function status(Submission $submission)
    {
        return response()->json(['submitted'=>(bool)$submission->submitted_at,'version'=>$submission->draft_version,'return_url'=>url('/activities/'.$submission->activity->getRouteKey())])->header('Cache-Control','private, no-store');
    }

    public function draft(Request $request, Submission $submission, QuizAttemptService $quizzes)
    {
        $this->authorizeAttempt($submission);
        if ($submission->submitted_at) return $this->status($submission);
        $data = $request->validate($quizzes->rules($submission->activity));
        $submission = DB::transaction(function () use ($submission, $data, $quizzes) {
            $locked = Submission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if ($locked->submitted_at) return $locked;
            $expires = $quizzes->expiresAt($locked);
            if ($expires && now()->gt($expires)) return $quizzes->finalize($locked, 'deadline');
            if (($data['draft_version'] ?? 0) > $locked->draft_version) {
                $locked->answers = collect($data['answers'] ?? [])->only($locked->activity->questions->modelKeys())->all();
                $locked->draft_version = $data['draft_version'];
                $locked->save();
            }
            return $locked;
        });
        return $this->status($submission);
    }

    public function finish(Request $request, Submission $submission, QuizAttemptService $quizzes)
    {
        $this->authorizeAttempt($submission);
        if ($submission->submitted_at) return $this->status($submission);
        $data = $request->validate([...$quizzes->rules($submission->activity),'reason'=>'required|in:fullscreen_exit,page_exit,deadline,load_failed']);
        $submission = $quizzes->finalize($submission, $data['reason'], $request->boolean('answers_present') ? ($data['answers'] ?? []) : null, $data['draft_version'] ?? 0);
        return $this->status($submission);
    }
}
