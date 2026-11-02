<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use Concerns\HasPublicSlug;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'available_at' => 'datetime', 'published' => 'boolean'];
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function group()
    {
        return $this->belongsTo(AssessmentGroup::class, 'assessment_group_id');
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'activity_assignments');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    // Activities created before assignment_mode was recorded are inferred from their group and participants.
    public function audienceMode(): string
    {
        if ($this->assignment_mode) {
            return $this->assignment_mode;
        }
        if ($this->assessment_group_id) {
            return 'group';
        }

        return $this->participants()->count() >= \App\Services\Access::staff()->count() ? 'all' : 'selected';
    }

    /** Assessments assigned to this person that are open now and that they have not submitted. */
    public static function pendingAssessments(User $user, int $programmeId): int
    {
        return static::where('programme_id', $programmeId)->where('kind', 'quiz')->where('published', true)
            ->whereHas('participants', fn ($q) => $q->where('users.id', $user->id))
            ->whereDoesntHave('submissions', fn ($q) => $q->where('user_id', $user->id)->whereNotNull('submitted_at'))
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('due_at')->orWhere('due_at', '>', now()))
            ->count();
    }

    public function label(): string
    {
        return ['quiz' => 'assessment', 'assignment' => 'assignment', 'presentation' => 'presentation'][$this->kind] ?? $this->kind;
    }
}
