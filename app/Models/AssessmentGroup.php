<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentGroup extends Model
{
    use Concerns\HasPublicSlug;

    protected $guarded = [];

    public function members()
    {
        return $this->belongsToMany(User::class, 'assessment_group_user');
    }
}
