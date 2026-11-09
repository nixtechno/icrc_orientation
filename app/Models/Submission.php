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
        return ['device' => 'array', 'answers' => 'array','started_at' => 'datetime', 'submitted_at' => 'datetime', 'released_at' => 'datetime', 'fullscreen_started_at' => 'datetime', 'draft_version' => 'integer', 'question_position' => 'integer'];
    }

    public static ?bool $positions = null;

    /** Whether the question position can be stored yet, so the app keeps working before that migration has run. */
    public static function tracksPosition(): bool
    {
        return self::$positions ??= \Illuminate\Support\Facades\Schema::hasColumn('submissions', 'question_position');
    }

    public static ?bool $devices = null;

    /** Whether the device can be recorded yet, so the app keeps working before that migration has run. */
    public static function recordsDevice(): bool
    {
        return self::$devices ??= \Illuminate\Support\Facades\Schema::hasColumn('submissions', 'device');
    }

    /** The browser details sent when an attempt starts, trimmed to what DeviceLabel reads; the user agent is always kept. */
    public static function deviceFrom(\Illuminate\Http\Request $r): ?array
    {
        if (! self::recordsDevice()) {
            return null;
        }
        $sent = json_decode((string) $r->input('device'), true);
        $sent = is_array($sent) ? $sent : [];

        return array_filter([
            'ua' => mb_substr((string) ($r->userAgent() ?? ''), 0, 1000),
            'model' => mb_substr(trim((string) ($sent['model'] ?? '')), 0, 100),
            'touch' => min(20, max(0, (int) ($sent['touch'] ?? 0))),
            'screen' => preg_match('/^\d{2,5}x\d{2,5}$/', (string) ($sent['screen'] ?? '')) ? $sent['screen'] : null,
            'standalone' => (bool) ($sent['standalone'] ?? false),
        ], fn ($value) => $value !== '' && $value !== null && $value !== 0 && $value !== false);
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
