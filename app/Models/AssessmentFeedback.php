<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AssessmentFeedback extends Model
{
    protected $table = 'assessment_feedback';

    protected $guarded = [];

    /** The steps of the feedback dialog, in order; each key has a matching {key}_rating column. */
    public const STEPS = [
        'facilitator' => ['title' => 'Facilitator', 'question' => 'How well did the facilitator teach this part of the programme?'],
        'content' => ['title' => 'Content of material', 'question' => 'How useful and clear was the material covered?'],
        'infrastructure' => ['title' => 'IT infrastructure', 'question' => 'How well did the equipment, network and room technology work?'],
        'portal' => ['title' => 'Portal usage', 'question' => 'How easy was the portal to use for this assessment?'],
    ];

    public const SCALE = [1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'];

    public static ?bool $ready = null;

    /** Whether feedback can be stored yet, so the app keeps working before that migration has run. */
    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('assessment_feedback');
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
