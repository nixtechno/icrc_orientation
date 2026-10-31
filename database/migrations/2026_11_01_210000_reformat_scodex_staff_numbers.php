<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Staff were imported as SCODEX2026001; the portal now uses SCODEX/ICRC/2026/01.
        $this->rewrite('/^SCODEX(\d{4})(\d+)$/', fn ($m) => sprintf('SCODEX/ICRC/%s/%02d', $m[1], (int) $m[2]));
    }

    public function down(): void
    {
        $this->rewrite('#^SCODEX/ICRC/(\d{4})/(\d+)$#', fn ($m) => sprintf('SCODEX%s%03d', $m[1], (int) $m[2]));
    }

    private function rewrite(string $pattern, callable $format): void
    {
        $convert = fn (?string $number) => $number !== null && preg_match($pattern, $number, $m) ? $format($m) : $number;
        DB::transaction(function () use ($convert) {
            foreach (DB::table('users')->whereNotNull('staff_number')->get(['id', 'staff_number']) as $user) {
                $number = $convert($user->staff_number);
                // Leave a number alone if its new form already belongs to someone else (staff numbers are unique).
                if ($number !== $user->staff_number && ! DB::table('users')->where('staff_number', $number)->exists()) {
                    DB::table('users')->where('id', $user->id)->update(['staff_number' => $number]);
                }
            }
            foreach (DB::table('daily_attendance')->whereNotNull('staff_number_snapshot')->get(['id', 'staff_number_snapshot']) as $row) {
                $number = $convert($row->staff_number_snapshot);
                if ($number !== $row->staff_number_snapshot) DB::table('daily_attendance')->where('id', $row->id)->update(['staff_number_snapshot' => $number]);
            }
            foreach (DB::table('badges')->whereNotNull('snapshot')->get(['id', 'snapshot']) as $badge) {
                $snapshot = json_decode($badge->snapshot, true);
                if (! is_array($snapshot) || ! isset($snapshot['staff_number'])) continue;
                $number = $convert($snapshot['staff_number']);
                if ($number !== $snapshot['staff_number']) DB::table('badges')->where('id', $badge->id)->update(['snapshot' => json_encode([...$snapshot, 'staff_number' => $number])]);
            }
        });
    }
};
