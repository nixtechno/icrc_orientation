<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $guarded = [];

    protected $hidden = ['correct_answer'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}
