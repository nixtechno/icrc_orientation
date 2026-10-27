<?php

namespace App\Services;

use App\Models\ProgrammeDay;
use App\Models\ProgrammeSession;
use App\Models\TimetableProposal;
use Illuminate\Support\Facades\DB;

class SupplementImporter
{
    public static function snapshot(ProgrammeSession $session): array
    {
        return [
            'title' => $session->title,
            'facilitator' => $session->facilitator,
            'starts_at' => $session->starts_at?->format('Y-m-d H:i:s'),
            'ends_at' => $session->ends_at?->format('Y-m-d H:i:s'),
            'actual_minutes' => $session->actual_minutes,
            'type' => $session->type,
            'status' => $session->status,
            'attendance_required' => $session->attendance_required,
            'venue' => $session->venue,
            'reviewed' => $session->reviewed,
            'warnings' => $session->warnings,
            'source' => $session->source,
        ];
    }

    /** Stage changes; importing never changes the published timetable. */
    public function run(): int
    {
        return DB::transaction(function () {
            $document = json_decode(file_get_contents(database_path('data/michael-uwaechie-supplement.json')), true, 512, JSON_THROW_ON_ERROR);
            $day = null;
            $dayNumber = 0;
            $rowNumber = 0;
            $created = 0;
            foreach ($document['rows'] as $row) {
                $label = trim($row[0] ?? '');
                if (preg_match('/^Day\s*(\d+)\s*:/', $label, $match)) {
                    $dayNumber = (int) $match[1];
                    $day = ProgrammeDay::where('number', $dayNumber)->whereHas('week.programme', fn ($q) => $q->where('code', 'ICRC-2026'))->firstOrFail();
                    $rowNumber = 0;

                    continue;
                }
                if (! $day || $label === 'Time' || $label === '') {
                    continue;
                }
                $rowNumber++;
                [$start, $end] = app(TimetableImporter::class)->times($label, $day->date);
                if (! $start || ! $end) {
                    throw new \RuntimeException('Supplement time could not be parsed: '.$label);
                }
                // A changed end time is matched by day and start, never by title or row order.
                $session = ProgrammeSession::where('programme_day_id', $day->id)->where('starts_at', $start)->first();
                $warnings = [];
                $minutes = (int) $start->diffInMinutes($end);
                $duration = preg_replace('/\s+/', '', $row[1] ?? '');
                if (! preg_match('/^(\d+)minutes$/i', $duration, $match)) {
                    $warnings[] = 'Supplement has no valid stated duration; actual minutes are calculated from its time range.';
                } elseif ((int) $match[1] !== $minutes) {
                    $warnings[] = "Supplement states {$match[1]} minutes, but its time range is {$minutes} minutes.";
                }
                if ($session && $session->ends_at?->ne($end)) {
                    $warnings[] = 'Time range differs from the original timetable. Confirm the afternoon split before applying.';
                }
                if (! $session) {
                    $warnings[] = 'Additional event absent from the original timetable. Resolve overlapping entries before applying.';
                }
                $normalise = fn ($value) => preg_replace('/\s+/u', '', (string) $value);
                if ($session) {
                    $original = $session->source['cells'];
                    $same = count($row) === count($original);
                    foreach ($row as $index => $value) {
                        $same = $same && $normalise($value) === $normalise($original[$index] ?? '');
                    }
                    if ($same) {
                        continue;
                    }
                }
                $title = $row[2] ?? null;
                $type = preg_match('/recap/i', $title ?? '') ? 'recap' : (preg_match('/presentations/i', $title ?? '') ? 'presentation' : ($session?->type ?? 'session'));
                $proposal = TimetableProposal::firstOrCreate(
                    ['source_key' => 'uwaechie-2026-d'.$dayNumber.'-r'.$rowNumber],
                    [
                        'programme_day_id' => $day->id,
                        'session_id' => $session?->id,
                        'source' => ['filename' => $document['filename'], 'sha256' => $document['sha256'], 'cells' => $row, 'day' => $day->source_label],
                        'baseline' => $session ? self::snapshot($session) : null,
                        'proposed' => ['title' => $title, 'facilitator' => $row[3] ?? null, 'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $end->format('Y-m-d H:i:s'), 'actual_minutes' => $minutes, 'type' => $type],
                        'warnings' => $warnings,
                    ]
                );
                $created += (int) $proposal->wasRecentlyCreated;
            }

            return $created;
        });
    }
}
