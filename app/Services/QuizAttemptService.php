<?php

namespace App\Services;

use App\Models\{Activity, Submission};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuizAttemptService
{
    public function rules(Activity $activity): array
    {
        $rules = ['answers' => 'nullable|array', 'draft_version' => 'sometimes|integer|min:0|max:2147483647', 'answers_present' => 'sometimes|boolean', 'position' => 'sometimes|integer|min:0|max:65535'];
        foreach ($activity->questions as $question) {
            $key = 'answers.'.$question->id;
            $rules[$key] = $question->type === 'multiple_answers' ? 'nullable|array|max:100' : 'nullable|string|max:10000';
            if ($question->type === 'multiple_answers') $rules[$key.'.*'] = ['required','string','distinct',Rule::in($question->options ?? [])];
        }
        return $rules;
    }

    /** Questions the person has moved past in this attempt. Questions are shown one at a time and cannot be revisited. */
    public function lockedIds(Submission $submission): array
    {
        return $submission->shuffled($submission->activity->questions)->take((int) $submission->question_position)->pluck('id')->all();
    }

    /**
     * Takes new answers while keeping the saved answer for every locked question, then moves the attempt forward to
     * $position. The position never moves back, so an earlier question cannot be reopened or changed.
     */
    public function accept(Submission $submission, array $answers, ?int $position = null): void
    {
        $questions = $submission->activity->questions;
        $locked = $this->lockedIds($submission);
        $stored = $submission->answers ?? [];
        $merged = collect($answers)->only($questions->modelKeys())->except($locked)->all();
        foreach ($locked as $id) {
            if (array_key_exists($id, $stored)) $merged[$id] = $stored[$id];
        }
        $submission->answers = $merged;
        if ($position !== null && Submission::tracksPosition()) {
            $submission->question_position = min(max((int) $submission->question_position, $position), max(0, $questions->count() - 1));
        }
    }

    /** The full score when every question is marked automatically; null while written answers need a facilitator. */
    public function score(Activity $activity, array $answers): ?float
    {
        return $this->writtenPoints($activity) ? null : $this->autoScore($activity, $answers);
    }

    /** Points available on written questions, which a facilitator marks. */
    public function writtenPoints(Activity $activity): int
    {
        return (int) $activity->questions->whereIn('type', ['short_answer', 'long_answer'])->sum('points');
    }

    /** Marks earned on the automatically marked questions; written questions are left out. */
    public function autoScore(Activity $activity, array $answers): float
    {
        $score = 0;
        foreach ($activity->questions as $question) {
            if (in_array($question->type, ['short_answer','long_answer'])) continue;
            if ($question->type === 'multiple_answers') {
                $selected = $answers[$question->id] ?? []; $correct = $question->correctAnswers();
                sort($selected); sort($correct);
                if ($correct && $selected === $correct) $score += $question->points;
            } elseif (isset($answers[$question->id]) && hash_equals($question->correct_answer ?? '', (string) $answers[$question->id])) $score += $question->points;
        }
        return $score;
    }

    /**
     * Each question with the person's answer and how it was marked, using the same rules as score().
     * Status is correct, wrong, unanswered or written (marked by a facilitator as part of the overall score).
     *
     * @return list<array{question: \App\Models\Question, selected: list<string>, response: string, correct: list<string>, status: string}>
     */
    public function review(Activity $activity, Submission $submission): array
    {
        return $submission->shuffled($activity->questions)->map(function ($question) use ($submission) {
            $answer = $submission->answers[$question->id] ?? null;
            if (in_array($question->type, ['short_answer', 'long_answer'], true)) {
                $response = trim((string) $answer);

                return ['question' => $question, 'selected' => [], 'response' => $response, 'correct' => [], 'status' => $response === '' ? 'unanswered' : 'written'];
            }
            $selected = array_values(array_filter(array_map('strval', (array) $answer), fn ($value) => $value !== ''));
            $correct = $question->type === 'multiple_answers' ? $question->correctAnswers() : array_filter([(string) $question->correct_answer], fn ($value) => $value !== '');
            [$mine, $right] = [$selected, $correct];
            sort($mine); sort($right);
            $status = match (true) {
                ! $selected => 'unanswered',
                $right && $mine === $right => 'correct',
                default => 'wrong',
            };

            return ['question' => $question, 'selected' => $selected, 'response' => '', 'correct' => array_values($correct), 'status' => $status];
        })->all();
    }

    public function expiresAt(Submission $submission): ?\Carbon\CarbonInterface
    {
        $expires = $submission->activity->due_at?->copy();
        if ($submission->activity->time_limit) {
            $timer = $submission->started_at->copy()->addMinutes($submission->activity->time_limit);
            if (! $expires || $timer->lt($expires)) $expires = $timer;
        }
        return $expires;
    }

    public function finalize(Submission $submission, string $reason, ?array $answers = null, int $revision = 0): Submission
    {
        return DB::transaction(function () use ($submission, $reason, $answers, $revision) {
            $locked = Submission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if ($locked->submitted_at) {
                return $answers !== null ? $this->deliverLate($locked, $reason, $answers, $revision) : $locked;
            }
            // The device freezes its copy when the attempt ends, so a final copy is accepted even when a poor
            // connection delays it past the deadline. Drafts sent after the deadline are still refused.
            if ($answers !== null && $revision >= $locked->draft_version) {
                $this->accept($locked, $answers);
                $locked->draft_version = $revision;
            }
            $locked->score = $this->score($locked->activity, $locked->answers ?? []);
            // Fully auto-marked assessments show the score straight away; written answers wait for a reviewer.
            $locked->released_at = $locked->score !== null ? now() : null;
            $locked->submitted_at = now();
            $locked->submission_reason = $reason;
            $locked->save();
            Access::audit('submission.submit', (string) $locked->id, null, ['reason'=>$reason,'automatic'=>$reason !== 'manual']);
            return $locked;
        });
    }

    /**
     * A device's final copy that arrives after the server has already closed the attempt from its last saved draft,
     * for example when the page was reopened before a poor connection let the final submission through. The newer
     * copy replaces the partial one, unless the answers have been released or a facilitator has already marked it.
     * The device's final copy is frozen when the attempt ends, so it carries nothing written after the attempt ended.
     */
    private function deliverLate(Submission $locked, string $reason, array $answers, int $revision): Submission
    {
        $activity = $locked->activity;
        $marked = filled($locked->feedback) || ($this->writtenPoints($activity) && ($locked->score !== null || $locked->released_at));
        // Only attempts the server closed by itself (page reopened, or the deadline passed) are waiting for the device's
        // copy. When the device's own final submission arrived, that copy is final and nothing replaces it.
        $closedByServer = in_array($locked->submission_reason, ['reopened', 'deadline'], true);
        if (! $closedByServer || $revision <= $locked->draft_version || $activity->answersReleased() || $marked) {
            return $locked;
        }
        $previous = $locked->submission_reason;
        $this->accept($locked, $answers);
        $locked->draft_version = $revision;
        $locked->score = $this->score($activity, $locked->answers ?? []);
        $locked->released_at = $locked->score !== null ? ($locked->released_at ?? now()) : null;
        $locked->submission_reason = $reason;
        $locked->save();
        Access::audit('submission.late_delivery', (string) $locked->id, null, ['reason'=>$reason,'replaced'=>$previous,'submitted_at'=>$locked->submitted_at?->toIso8601String()]);

        return $locked;
    }
}
