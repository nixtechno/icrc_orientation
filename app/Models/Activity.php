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

    public function label(): string
    {
        return ['quiz' => 'assessment', 'assignment' => 'assignment', 'presentation' => 'presentation'][$this->kind] ?? $this->kind;
    }
}
