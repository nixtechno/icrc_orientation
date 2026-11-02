<?php

namespace App\Services;

use App\Models\{Activity, Submission};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuizAttemptService
{
    public function rules(Activity $activity): array
    {
        $rules = ['answers' => 'nullable|array', 'draft_version' => 'sometimes|integer|min:0|max:2147483647', 'answers_present' => 'sometimes|boolean'];
        foreach ($activity->questions as $question) {
            $key = 'answers.'.$question->id;
            $rules[$key] = $question->type === 'multiple_answers' ? 'nullable|array|max:100' : 'nullable|string|max:10000';
            if ($question->type === 'multiple_answers') $rules[$key.'.*'] = ['required','string','distinct',Rule::in($question->options ?? [])];
        }
        return $rules;
    }

    public function score(Activity $activity, array $answers): ?float
    {
        $score = 0;
        foreach ($activity->questions as $question) {
            if (in_array($question->type, ['short_answer','long_answer'])) return null;
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
        return $activity->questions->map(function ($question) use ($submission) {
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
            if ($locked->submitted_at) return $locked;
            $expires = $this->expiresAt($locked);
            // Answers arriving after the deadline cannot replace the last valid saved draft.
            if ($answers !== null && $revision >= $locked->draft_version && (! $expires || now()->lte($expires))) {
                $locked->answers = collect($answers)->only($locked->activity->questions->modelKeys())->all();
                $locked->draft_version = $revision;
            }
            $locked->score = $this->score($locked->activity, $locked->answers ?? []);
            // Fully auto-marked assessments show the score straight away; written answers wait for a reviewer.
            $locked->released_at = $locked->score !== null ? now() : null;
            $locked->submitted_at = now();
            $locked->submission_reason = $reason;
            $locked->save();
            Access::audit('submission.submit', (string) $locked->id, null, ['reason'=>$reason,'automatic'=>true]);
            return $locked;
        });
    }
}
