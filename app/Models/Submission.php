<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use Concerns\HasPublicSlug;
    protected $guarded = [];
    protected $hidden = ['launch_token_hash'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'released_at' => 'datetime', 'fullscreen_started_at' => 'datetime', 'draft_version' => 'integer'];
    }

    /** The questions in this attempt's own order: shuffled per attempt, but the same every time it is shown. */
    public function shuffled($questions)
    {
        return $questions->sortBy(fn ($question) => hash('sha256', $this->id.':'.$question->id))->values();
    }

    /** A question's options in this attempt's own order. True/False keeps its usual order. */
    public function shuffledOptions($question): array
    {
        $options = $question->options ?? [];
        if ($question->type === 'true_false') {
            return $options;
        }
        usort($options, fn ($a, $b) => hash('sha256', $this->id.':'.$question->id.':'.$a) <=> hash('sha256', $this->id.':'.$question->id.':'.$b));

        return $options;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
