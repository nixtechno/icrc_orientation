<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $guarded = [];

    protected $hidden = ['correct_answer'];

    public function correctAnswers(): array
    {
        return $this->type === 'multiple_answers' ? (json_decode($this->correct_answer ?? '[]', true) ?: []) : [];
    }

    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}
