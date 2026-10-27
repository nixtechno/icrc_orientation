<?php

namespace App\Services;

use App\Models\Programme;
use App\Models\ProgrammeDay;
use App\Models\ProgrammeSession;
use App\Models\ProgrammeWeek;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TimetableImporter
{
    public const THEMES = ['Fundamentals of Public-Private Partnership', 'Institution Orientation and Public Service Readiness', 'The ICRC and Its Regulatory Processes', 'Case Studies and Practical Applications', 'Breakout Sessions on Thematic Areas', 'Individual Presentations, Dinner and Team Integration'];

    public function run(): Programme
    {
        return DB::transaction(function () {
            $p = Programme::firstOrCreate(['code' => 'ICRC-2026'], ['title' => 'ORIENTATION PROGRAMME FOR THE 2026 RECRUITED STAFF', 'starts_on' => '2026-11-02', 'ends_on' => '2026-12-11', 'timezone' => 'Africa/Lagos', 'settings' => ['lateness_minutes' => 15, 'window_minutes' => 60, 'excused_in_denominator' => false, 'about' => 'Equipping newly recruited staff with the knowledge, skills, and institutional understanding to contribute effectively to Nigeria’s infrastructure development.']]);
            $tables = json_decode(ltrim(file_get_contents(database_path('data/source-tables.json')), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
            $dayNumber = 0;
            foreach ($tables as $wi => $rows) {
                $week = ProgrammeWeek::firstOrCreate(['programme_id' => $p->id, 'number' => $wi + 1], ['theme' => self::THEMES[$wi]]);
                $day = null;
                $index = 0;
                foreach ($rows as $row) {
                    $label = trim($row[0] ?? '');
                    if (preg_match('/^Day\s/i', $label)) {
                        $dayNumber++;
                        $date = Carbon::parse('2026-11-02')->addWeeks($wi)->addDays(($dayNumber - 1) % 5);
                        $day = ProgrammeDay::firstOrCreate(['number' => $dayNumber], ['programme_week_id' => $week->id, 'date' => $date->toDateString(), 'source_label' => $label, 'induction_free' => $dayNumber === 30]);
                        $index = 0;

                        continue;
                    }if (! $day || $label === 'Time') {
                        continue;
                    }
                    $index++;
                    $title = $row[2] ?? null;
                    $fac = $row[3] ?? null;
                    $start = null;
                    $end = null;
                    $minutes = null;
                    $warnings = [];
                    $type = 'session';
                    if ($dayNumber === 29) {
                        $title = $label;
                        $start = $day->date->copy()->setTime(16, 0);
                        $type = 'dinner';
                        $warnings[] = 'Dinner end time not supplied';
                    } elseif ($dayNumber === 30) {
                        $title = $label;
                        $type = 'free_day';
                    } else {
                        [$start,$end] = $this->times($label, $day->date);
                        if ($start && $end) {
                            $minutes = (int) $start->diffInMinutes($end);
                        } else {
                            $warnings[] = 'Time range requires review';
                        }$stated = preg_replace('/\s+/', '', $row[1] ?? '');
                        if (! preg_match('/^(\d+)minutes$/i', $stated, $m)) {
                            $warnings[] = 'Invalid stated duration';
                        } elseif ((int) $m[1] !== $minutes) {
                            $warnings[] = 'Stated duration differs from time range';
                        }if (! trim($title ?? '')) {
                            $warnings[] = 'Missing course title: unconfirmed slot';
                        }if (! trim($fac ?? '') || preg_match('/\b(TBD|TBB)\b/', $fac)) {
                            $warnings[] = 'Facilitator requires confirmation';
                        }if (preg_match('/tea break/i', $title ?? '')) {
                            $type = 'break';
                        } elseif (preg_match('/lunch/i', $title ?? '')) {
                            $type = 'lunch';
                        } elseif (preg_match('/recap/i', $title ?? '')) {
                            $type = 'recap';
                        } elseif (preg_match('/presentation/i', $title ?? '')) {
                            $type = 'presentation';
                        }
                    }
                    ProgrammeSession::firstOrCreate(['source_key' => "2026-d{$dayNumber}-{$index}"], ['programme_day_id' => $day->id, 'title' => $title ?: null, 'facilitator' => $fac ?: null, 'type' => $type, 'starts_at' => $start, 'ends_at' => $end, 'actual_minutes' => $minutes, 'source' => ['cells' => $row, 'day' => $day->source_label], 'warnings' => $warnings, 'attendance_required' => false]);
                }
            }

return $p;
        });
    }

    public function times(string $raw, Carbon $date): array
    {
        $s = preg_replace('/\s+/', '', strtolower($raw));
        if (! preg_match('/(\d{1,2}):(\d{2})(am|pm)?[–—-](\d{1,2}):(\d{2})(am|pm)/u', $s, $m)) {
            return [null, null];
        }$h1 = (int) $m[1];
        $h2 = (int) $m[4];
        $mer = $m[6];
        $eh = $h2 % 12 + ($mer === 'pm' ? 12 : 0);
        $sh = $h1 % 12;
        if (($m[3] ?? '') === 'pm') {
            $sh += 12;
        } elseif (empty($m[3]) && $mer === 'pm' && $h1 < 8) {
            $sh += 12;
        }$start = $date->copy()->setTime($sh, (int) $m[2]);
        $end = $date->copy()->setTime($eh, (int) $m[5]);

        return $end->gt($start) ? [$start, $end] : [null, null];
    }
}
