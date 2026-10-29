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

    public function participants()
    {
        return $this->belongsToMany(User::class, 'activity_assignments');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }
}
