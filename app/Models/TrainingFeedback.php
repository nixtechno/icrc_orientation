<?php

namespace App\Models;

use App\Services\Access;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Staff ratings of a programme day's training, asked once a day on the portal at the feedback time set in Data tools. */
class TrainingFeedback extends Model
{
    protected $table = 'training_feedback';

    protected $guarded = [];

    /** The rating steps of the form, in order; each key has a matching {key}_rating column. */
    public const STEPS = [
        'overall' => ['title' => 'Overall session', 'question' => 'How would you rate today\'s overall training session?'],
        'facilitator' => ['title' => 'Instructor', 'question' => 'How would you rate the instructor\'s knowledge, presentation, and delivery?'],
        'relevance' => ['title' => 'Relevance to your job', 'question' => 'How relevant was today\'s training to your new job responsibilities?'],
        'understanding' => ['title' => 'Understanding', 'question' => 'How well did you understand the topics covered in today\'s training?'],
        'infrastructure' => ['title' => 'ICT and facilities', 'question' => 'How would you rate the ICT equipment, internet connectivity, and training facilities?'],
        'food' => ['title' => 'Food and refreshments', 'question' => 'How would you rate the quality of food and refreshments provided today?'],
    ];

    public const SCALE = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'];

    public const DEFAULT_TIME = '16:30';

    public static ?bool $ready = null;

    /** Whether feedback can be stored yet, so the app keeps working before that migration has run. */
    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('training_feedback');
    }

    /** The time of day (HH:MM, programme time) the form appears, set in Data tools. */
    public static function time(Programme $programme): string
    {
        $time = $programme->settings['feedback_time'] ?? null;

        return is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : self::DEFAULT_TIME;
    }

    /** Today's programme day when it is not cancelled, or null on days without training. */
    public static function today(Programme $programme): ?ProgrammeDay
    {
        return ProgrammeDay::whereHas('week', fn ($q) => $q->where('programme_id', $programme->id))->whereDate('date', now($programme->timezone)->toDateString())->where('cancelled', false)->first();
    }

    /** When the form opens today, or null when this person is not asked today (not staff, no training, or already answered). */
    public static function opensFor(?User $user): ?Carbon
    {
        if (! $user || $user->role !== 'staff' || ! $user->active || ! self::ready()) {
            return null;
        }
        $programme = Access::programme();
        $day = self::today($programme);
        if (! $day || ! $user->programmes()->whereKey($programme->id)->exists() || self::where('programme_day_id', $day->id)->where('user_id', $user->id)->exists()) {
            return null;
        }

        return Carbon::parse($day->date->toDateString().' '.self::time($programme), $programme->timezone);
    }

    public function day()
    {
        return $this->belongsTo(ProgrammeDay::class, 'programme_day_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
