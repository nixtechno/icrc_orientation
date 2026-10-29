<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ProgrammeSession;
use App\Models\ProgrammeWeek;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;
use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ActivityController extends Controller
{
    private function scope(Activity $activity): void
    {
        abort_unless($activity->programme_id === Access::programme()->id, 404);
    }

    private function participant(Activity $activity): void
    {
        $this->scope($activity);
        abort_unless($activity->published && $activity->participants()->where('users.id', auth()->id())->exists(), 403);
    }

    public function index(Request $r)
    {
        $manager = $r->user()->canManage('assessments');
        $q = Activity::where('programme_id', Access::programme()->id);
        if (! $manager) {
            $q->where('published', true)->whereHas('participants', fn ($q) => $q->where('users.id', auth()->id()));
        }if ($r->filled('kind')) {
            $q->where('kind', $r->kind);
        }

return view('activities', ['items' => $q->with('group')->withCount('submissions')->latest()->get(), 'manager' => $manager,
    'groups' => $manager ? \App\Models\AssessmentGroup::where('programme_id', Access::programme()->id)->withCount('members')->orderBy('name')->get() : collect(),
    'weeks' => $manager ? Access::programme()->weeks()->with('days.sessions')->get() : collect()]);
    }

    public function store(Request $r)
    {
        Access::manage('assessments');
        $v = $r->validate(['kind' => 'required|in:quiz,assignment,presentation', 'title' => 'required|string|max:255', 'instructions' => 'required|string|max:20000', 'available_at' => 'nullable|date|before:due_at', 'due_at' => 'required|date|after:now', 'attempts' => 'required|integer|between:1,10', 'time_limit' => 'nullable|integer|between:1,240', 'assignment_mode' => 'nullable|in:all,group,selected', 'group_name' => 'nullable|string|max:255', 'participants' => 'nullable|array', 'participants.*' => 'integer', 'programme_week_id' => 'nullable|integer', 'session_id' => 'nullable|integer']);
        $r->validate(['programme_week_id' => 'nullable|required_with:session_id|integer', 'assessment_group_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::requiredIf(fn () => $r->input('assignment_mode') === 'group' && ! $r->filled('group_name'))]]);
        $mode = $v['assignment_mode'] ?? 'all';
        $q = Access::staff();
        if ($mode === 'selected') {
            $q->whereIn('id', $v['participants'] ?? []);
        } elseif ($mode === 'group') {
            if ($r->filled('assessment_group_id')) {
                $group = \App\Models\AssessmentGroup::where('programme_id', Access::programme()->id)->findOrFail($r->integer('assessment_group_id'));
                $q->whereIn('users.id', $group->members()->select('users.id'));
                $v['assessment_group_id'] = $group->id;
            } else {
                // Keep earlier programme-group integrations usable.
                $q->where('group_name', $v['group_name'] ?? null);
            }
        }$ids = $q->pluck('id');
        abort_unless($ids->count() > 0 && ($mode !== 'selected' || $ids->count() === count(array_unique($v['participants'] ?? []))), 422, 'Select valid participants or a non-empty group.');
        if (! empty($v['programme_week_id'])) {
            abort_unless(ProgrammeWeek::where('programme_id', Access::programme()->id)->whereKey($v['programme_week_id'])->exists(), 422);
        }if (! empty($v['session_id'])) {
            $session = ProgrammeSession::with('day')->findOrFail($v['session_id']);
            abort_unless($session->day->week->programme_id === Access::programme()->id, 422);
            if (! empty($v['programme_week_id'])) {
                abort_unless($session->day->programme_week_id == (int) $v['programme_week_id'], 422, 'The selected session must belong to the selected week.');
            }
        }DB::transaction(function () use ($v, $ids) {
            $a = Activity::create([...collect($v)->except(['participants', 'assignment_mode', 'group_name'])->all(), 'programme_id' => Access::programme()->id]);
            $a->participants()->sync($ids);
            Access::audit('activity.create', (string) $a->id);
        });

        return back()->with('success', 'Activity created as draft. Add questions before publishing a quiz.');
    }

    public function show(Activity $activity)
    {
        $this->scope($activity);
        $manager = auth()->user()->canManage('assessments');
        if (! $manager) {
            $this->participant($activity);
        }$activity->load('questions');
        $submissions = $manager ? $activity->submissions()->with('user')->get() : $activity->submissions()->where('user_id', auth()->id())->get();

        return view('activity', compact('activity', 'manager', 'submissions'));
    }

    public function question(Request $r, Activity $activity)
    {
        Access::manage('assessments');
        $this->scope($activity);
        abort_if($activity->published || $activity->submissions()->exists(), 422, 'Questions are locked after publication or an attempt.');
        $v = $r->validate(['type' => 'required|in:multiple_choice,multiple_answers,true_false,short_answer,long_answer', 'prompt' => 'required|string|max:10000', 'options' => 'nullable|string|max:10000', 'correct_answer' => 'nullable|string|max:5000', 'points' => 'required|integer|between:1,100']);
        $options = $v['type'] === 'true_false' ? ['True', 'False'] : array_values(array_unique(array_filter(array_map('trim', explode("\n", $v['options'] ?? '')), fn ($option) => $option !== '')));
        if (in_array($v['type'], ['multiple_choice', 'true_false'])) {
            abort_unless(count($options) >= 2 && in_array($v['correct_answer'] ?? null, $options, true), 422, 'Provide at least two options and an exact matching correct answer.');
        }
        if ($v['type'] === 'multiple_answers') {
            $correct = array_values(array_unique(array_filter(array_map('trim', explode("\n", $v['correct_answer'] ?? '')), fn ($value) => $value !== '')));
            abort_unless(count(array_unique($options)) >= 2 && count($correct) >= 2 && ! array_diff($correct, $options), 422, 'Provide at least two options and two correct answers, one exact option per line.');
            $v['correct_answer'] = json_encode($correct, JSON_THROW_ON_ERROR);
        }
        Question::create([...$v, 'activity_id' => $activity->id, 'options' => $options]);
        Access::audit('question.create', (string) $activity->id);

        return back()->with('success', 'Question added.');
    }

    public function publish(Activity $activity)
    {
        Access::manage('assessments');
        $this->scope($activity);
        abort_if(! $activity->published && $activity->kind === 'quiz' && ! $activity->questions()->exists(), 422, 'Add questions first.');
        $activity->update(['published' => ! $activity->published]);
        Access::audit('activity.publication', (string) $activity->id);

        return back()->with('success', 'Publication updated.');
    }

    public function start(Activity $activity)
    {
        $this->participant($activity);
        abort_unless(auth()->user()->role === 'staff', 403);
        abort_if(now()->gt($activity->due_at) || ($activity->available_at && now()->lt($activity->available_at)), 422, 'Activity is outside its availability window.');
        $submission = DB::transaction(function () use ($activity) {
            User::whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            $q = $activity->submissions()->where('user_id', auth()->id());
            $open = (clone $q)->whereNull('submitted_at')->first();
            if ($open) {
                return $open;
            }abort_if($q->count() >= $activity->attempts, 422, 'No attempts remaining.');

            return Submission::create(['activity_id' => $activity->id, 'user_id' => auth()->id(), 'attempt' => $q->count() + 1, 'started_at' => now()]);
        });

        return redirect('/submissions/'.$submission->getRouteKey().'/complete');
    }

    private function owns(Submission $s): void
    {
        abort_unless($s->user_id === auth()->id(), 403);
        $this->participant($s->activity);
    }

    public function complete(Submission $submission)
    {
        $this->owns($submission);
        if ($submission->submitted_at) {
            return redirect('/activities/'.$submission->activity->getRouteKey())->with('success', 'This attempt has already been submitted. Your saved answers have not changed.');
        }

        return view('complete', ['submission' => $submission, 'activity' => $submission->activity->load('questions')]);
    }

    public function submit(Request $r, Submission $submission)
    {
        $this->owns($submission);
        $a = $submission->activity;
        if ($submission->submitted_at) {
            return redirect('/activities/'.$a->getRouteKey())->with('success', 'This attempt has already been submitted. Your saved answers have not changed.');
        }
        abort_if(now()->gt($a->due_at) || ($a->time_limit && now()->gt($submission->started_at->copy()->addMinutes($a->time_limit))), 422, 'The submission deadline or time limit has passed.');
        $answerRules = ['body' => 'nullable|string|max:50000', 'answers' => 'nullable|array', 'file' => 'nullable|file|mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png|max:10240'];
        foreach ($a->questions as $question) {
            $key = 'answers.'.$question->id;
            $answerRules[$key] = $question->type === 'multiple_answers' ? 'nullable|array|max:100' : 'nullable|string|max:10000';
            if ($question->type === 'multiple_answers') {
                $answerRules[$key.'.*'] = ['required', 'string', 'distinct', \Illuminate\Validation\Rule::in($question->options ?? [])];
            }
        }
        $v = $r->validate($answerRules);
        if ($a->kind !== 'quiz' && ! $r->filled('body') && ! $r->hasFile('file')) {
            return back()->withErrors(['body' => 'Submit text or a permitted file.']);
        }$answers = $v['answers'] ?? [];
        $score = 0;
        $written = false;
        foreach ($a->questions as $q) {
            if (in_array($q->type, ['short_answer', 'long_answer'])) {
                $written = true;
            } elseif ($q->type === 'multiple_answers') {
                $selected = $answers[$q->id] ?? [];
                $correct = $q->correctAnswers();
                sort($selected); sort($correct);
                if ($correct && $selected === $correct) $score += $q->points;
            } elseif (isset($answers[$q->id]) && hash_equals($q->correct_answer ?? '', (string) $answers[$q->id])) {
                $score += $q->points;
            }
        }$saved = DB::transaction(function () use ($submission, $a, $v, $answers, $score, $written, $r) {
            $s = Submission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if ($s->submitted_at) {
                return false;
            }
            $s->update(['body' => $v['body'] ?? null, 'answers' => collect($answers)->only($a->questions->pluck('id')->all())->all(), 'path' => $r->file('file')?->store('submissions', 'local'), 'filename' => $r->file('file')?->getClientOriginalName(), 'score' => $a->kind === 'quiz' && ! $written ? $score : null, 'submitted_at' => now()]);
            Access::audit('submission.submit', (string) $s->id);
            return true;
        });

        return redirect('/activities/'.$a->getRouteKey())->with('success', $saved
            ? 'Work submitted. Results remain private until released.'
            : 'This attempt has already been submitted. Your saved answers have not changed.');
    }

    public function review(Request $r, Submission $submission)
    {
        Access::manage('assessments');
        $this->scope($submission->activity);
        abort_unless($submission->submitted_at, 422);
        $v = $r->validate(['score' => 'required|numeric|min:0|max:100000', 'feedback' => 'nullable|string|max:20000']);
        $before = $submission->toArray();
        $submission->update([...$v, 'released_at' => $r->boolean('release') ? now() : null]);
        Access::audit('submission.review', (string) $submission->id, $before, $submission->toArray());

        return back()->with('success', 'Review saved.');
    }

    public function download(Submission $submission)
    {
        $this->scope($submission->activity);
        abort_unless($submission->user_id === auth()->id() || auth()->user()->canManage('assessments'),403);
        abort_unless($submission->path,404);

        return Storage::disk('local')->download($submission->path,$submission->filename);
    }
}
