<?php

namespace App\Http\Controllers;

use App\Services\{Access, AttendanceSync};
use Illuminate\Http\Request;

class AttendanceSyncController extends Controller
{
    /** Live site: answers the venue server, which authenticates with the shared sync token. */
    private function venueOnly(Request $request): void
    {
        abort_if(AttendanceSync::venue(), 404);
        abort_unless(AttendanceSync::tokenValid($request->bearerToken()), 401, 'Invalid sync token.');
    }

    public function ping(Request $request)
    {
        $this->venueOnly($request);

        return response()->json(['time' => now()->toIso8601String()]);
    }

    public function snapshot(Request $request, AttendanceSync $sync)
    {
        $this->venueOnly($request);
        $since = $request->validate(['since' => 'nullable|date'])['since'] ?? null;

        return response()->json($sync->snapshot($since))->header('Cache-Control', 'private, no-store');
    }

    public function upload(Request $request, AttendanceSync $sync)
    {
        $this->venueOnly($request);
        $v = $request->validate([
            'origin' => 'required|string|max:60',
            'records' => 'present|array|max:500',
            'records.*.uuid' => 'required|uuid',
            'records.*.date' => 'required|date_format:Y-m-d',
            'records.*.email' => 'nullable|email|max:255',
            'records.*.qr_code' => 'nullable|digits_between:1,20',
            'records.*.checked_in_at' => 'nullable|date',
            'records.*.checked_out_at' => 'nullable|date',
            'logs' => 'sometimes|array|max:1000',
            'logs.*.uuid' => 'required|uuid',
            'logs.*.event' => 'required|string|max:255',
            'logs.*.created_at' => 'required|date',
        ]);

        return response()->json($sync->receive($request->input('records', []), $request->input('logs', []), $v['origin']));
    }

    /** Both copies: the sync line on the scanner pages. */
    public function status(AttendanceSync $sync)
    {
        Access::manage('attendance');

        return response()->json($sync->status())->header('Cache-Control', 'private, no-store');
    }

    public function now(AttendanceSync $sync)
    {
        Access::manage('attendance');
        abort_unless(AttendanceSync::venue(), 404);

        return response()->json($sync->run());
    }
}
