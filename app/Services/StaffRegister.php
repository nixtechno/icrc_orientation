<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\DailyAttendance;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * One sheet with every enrolled staff member: their mark for each programme day so far, then each published activity.
 * Days are the elapsed, uncancelled ones that take attendance or have scans, as in the programme report; any scan counts as attended.
 */
class StaffRegister
{
    public const NOT_ENROLLED = '—';

    public function build(): Spreadsheet
    {
        $p = Access::programme();
        $today = now($p->timezone ?: 'Africa/Lagos')->toDateString();
        $staff = Access::staff()->orderBy('name')->get(['users.id', 'name', 'staff_number', 'email', 'active']);
        $ids = $staff->modelKeys();
        $enrolled = DB::table('enrolments')->where('programme_id', $p->id)->whereIn('user_id', $ids)->pluck('created_at', 'user_id');
        $records = DailyAttendance::where('programme_id', $p->id)->whereIn('user_id', $ids)->toBase()
            ->get(['user_id', 'programme_day_id', 'checked_in_at', 'checked_out_at'])->keyBy(fn ($r) => $r->user_id.':'.$r->programme_day_id);
        $scanned = $records->pluck('programme_day_id')->unique();
        $days = app(AttendanceService::class)->days()->where('cancelled', false)
            ->where(fn ($q) => $q->where('attendance_required', true)->orWhereIn('id', $scanned))
            ->whereBetween('date', [$p->starts_on->toDateString(), min($p->ends_on->toDateString(), $today)])
            ->orderBy('date')->get();

        $activities = Activity::where('programme_id', $p->id)->where('published', true)->withSum('questions as total_points', 'points')
            ->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')->get();
        $assigned = DB::table('activity_assignments')->whereIn('activity_id', $activities->modelKeys())->whereIn('user_id', $ids)->get()
            ->map(fn ($a) => $a->activity_id.':'.$a->user_id)->flip();
        // Each person's latest attempt, as the programme report counts it.
        $latest = DB::table('submissions')->whereIn('activity_id', $activities->modelKeys())->whereIn('user_id', $ids)->orderBy('attempt')
            ->get(['activity_id', 'user_id', 'submitted_at', 'released_at', 'score'])->keyBy(fn ($s) => $s->activity_id.':'.$s->user_id);

        $book = new Spreadsheet;
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $ws = $book->getActiveSheet()->setTitle('Attendance & Assessments');
        $col = fn (int $i) => Coordinate::stringFromColumnIndex($i);

        $ws->setCellValue('A1', $p->title.' — Staff attendance and assessments (as of '.now($p->timezone ?: 'Africa/Lagos')->format('j M Y, H:i').')');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $ws->setCellValue('A2', 'Days: Present = signed in or out that day · Absent = no scan · '.self::NOT_ENROLLED.' = not yet enrolled. '
            .'Assessments: the score on the latest attempt, out of the points in the heading · Awaiting marking · Not submitted · Not assigned.');
        $ws->getStyle('A2')->getFont()->setItalic(true)->setSize(9);

        $heads = ['S/N', 'Name', 'Staff No.', 'Email', 'Account', ...$days->map(fn ($d) => $d->date->format('D j M'))->all(),
            ...$activities->map(fn ($a) => $a->title.' ('.ucfirst($a->label()).((float) $a->total_points > 0 ? ', out of '.(float) $a->total_points : '').')')->all()];
        $first = 6;
        $lastDay = $first + $days->count() - 1;
        $last = count($heads);
        $ws->fromArray($heads, null, 'A4');

        $row = 5;
        foreach ($staff->values() as $i => $u) {
            $cells = [$i + 1, $u->name, $u->staff_number, $u->email, $u->active ? 'Active' : 'Not activated'];
            $since = isset($enrolled[$u->id]) ? substr((string) $enrolled[$u->id], 0, 10) : null;
            foreach ($days as $day) {
                $r = $records->get($u->id.':'.$day->id);
                $cells[] = match (true) {
                    $r && ($r->checked_in_at || $r->checked_out_at) => 'Present',
                    $since && $since > $day->date->toDateString() => self::NOT_ENROLLED,
                    default => 'Absent',
                };
            }
            foreach ($activities as $a) {
                $s = $latest->get($a->id.':'.$u->id);
                $cells[] = match (true) {
                    ! $assigned->has($a->id.':'.$u->id) => 'Not assigned',
                    ! $s || ! $s->submitted_at => 'Not submitted',
                    $s->score !== null => (float) $s->score,
                    default => 'Awaiting marking',
                };
            }
            $ws->fromArray($cells, null, 'A'.$row, true);
            $row++;
        }
        $end = max(5, $row - 1);

        if ($days->isNotEmpty() && $staff->isNotEmpty()) {
            $any = 'COUNTIF(%1$s,"Present")';
            $due = 'COUNTA(%1$s)-COUNTIF(%1$s,"'.self::NOT_ENROLLED.'")';
            foreach (['Present' => '='.$any, 'Absent' => '=COUNTIF(%1$s,"Absent")', 'Attendance %' => '=IF('.$due.'=0,"",('.$any.')/('.$due.'))'] as $label => $formula) {
                $ws->setCellValue('B'.$row, $label);
                for ($c = $first; $c <= $lastDay; $c++) {
                    $ws->setCellValue($col($c).$row, sprintf($formula, $col($c).'5:'.$col($c).$end));
                }
                $ws->getStyle('A'.$row.':'.$col($last).$row)->applyFromArray(['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']]]);
                if ($label === 'Attendance %') {
                    $ws->getStyle($col($first).$row.':'.$col($lastDay).$row)->getNumberFormat()->setFormatCode('0%');
                }
                $row++;
            }
        }

        $ws->getStyle('A4:'.$col($last).'4')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']], 'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true]]);
        $ws->getStyle('A4:'.$col($last).($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('BFBFBF');
        $ws->getStyle('A5:A'.$end)->getAlignment()->setHorizontal('center');
        if ($last >= $first) {
            $ws->getStyle($col($first).'5:'.$col($last).($row - 1))->getAlignment()->setHorizontal('center');
        }
        if ($days->isNotEmpty()) {
            $marks = [];
            foreach (['Present' => 'C6EFCE', 'Absent' => 'FFC7CE'] as $value => $rgb) {
                $mark = (new Conditional)->setConditionType(Conditional::CONDITION_CELLIS)->setOperatorType(Conditional::OPERATOR_EQUAL)->addCondition('"'.$value.'"');
                $mark->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setRGB($rgb);
                $marks[] = $mark;
            }
            $ws->getStyle($col($first).'5:'.$col($lastDay).$end)->setConditionalStyles($marks);
        }
        if ($activities->isNotEmpty()) {
            $ws->getStyle($col($lastDay + 1).'5:'.$col($last).$end)->getNumberFormat()->setFormatCode('General');
        }

        foreach ([1 => 6, 2 => 34, 3 => 24, 4 => 32, 5 => 13] as $c => $width) {
            $ws->getColumnDimension($col($c))->setWidth($width);
        }
        for ($c = $first; $c <= $last; $c++) {
            $ws->getColumnDimension($col($c))->setWidth($c > $lastDay ? 18 : 10);
        }
        $ws->getRowDimension(4)->setRowHeight(42);
        $ws->freezePane('C5');
        $ws->setAutoFilter('A4:'.$col($last).$end);

        return $book;
    }
}
