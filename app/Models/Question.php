<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Question extends Model
{
    protected $guarded = [];

    protected $hidden = ['correct_answer'];

    /** Remembered per request; reset it after changing the questions table. */
    public static ?bool $explanations = null;

    /** Whether explanations can be stored yet, so the app keeps working before that migration has run. */
    public static function hasExplanations(): bool
    {
        return self::$explanations ??= Schema::hasColumn('questions', 'explanation');
    }

    public function correctAnswers(): array
    {
        return $this->type === 'multiple_answers' ? (json_decode($this->correct_answer ?? '[]', true) ?: []) : [];
    }

    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}
