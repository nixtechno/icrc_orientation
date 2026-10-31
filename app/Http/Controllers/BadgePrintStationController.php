<?php

namespace App\Http\Controllers;

use App\Models\{Badge, User};
use App\Services\{Access, BadgeService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Self-service badge printing: a person scans the print code from their invitation email and
 * their badge opens in the station's print dialog. Operated by an administrator's session.
 */
class BadgePrintStationController extends Controller
{
    private function admin(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
    }

    public function index()
    {
        $this->admin();
        return response()->view('badges.print-station', ['logoUrl' => Access::programme()->logoUrl()])->header('Cache-Control', 'private, no-store');
    }

    public function scan(Request $request, BadgeService $badges)
    {
        $this->admin();
        // Invitation emails show the code in groups of four; accept it typed that way too.
        $code = preg_replace('/\s+/', '', $request->validate(['code' => 'required|string|max:64'])['code']);
        abort_unless(ctype_digit($code), 422, 'Scan the badge printing QR code from the invitation email.');
        $programme = Access::programme();
        $person = User::where('print_code', $code)->first();
        abort_unless($person, 422, 'This is not a badge printing QR code. Scan the QR code from the invitation email.');
        abort_unless(in_array($person->role, User::ROLES, true) && $person->programmes()->where('programmes.id', $programme->id)->exists(), 422, $person->name.' is not enrolled in this programme.');

        [$badge, $reprint] = DB::transaction(function () use ($person, $programme, $badges) {
            $key = 'u:'.$person->id;
            $badge = Badge::where('programme_id', $programme->id)->where('subject_key', $key)->lockForUpdate()->first();
            if (! $badge) {
                $badge = Badge::create(['programme_id' => $programme->id, 'subject_key' => $key, 'user_id' => $person->id, 'category' => $person->role, 'snapshot' => $badges->snapshot($person, 'u'), 'version' => 1, 'generated_at' => now(), 'generated_by' => auth()->id()]);
                Access::audit('badge.generate', (string) $badge->id, null, ['category' => $badge->category, 'version' => 1, 'source' => 'print_station']);
            }
            $reprint = $badge->printed_at;
            $badge->printed_at ??= now();
            $badge->save();
            Access::audit('badge.print_station', (string) $badge->id, null, ['reprint' => (bool) $reprint]);
            return [$badge, $reprint];
        });

        $message = $reprint
            ? $person->name.' — this badge was first printed on '.$reprint->timezone('Africa/Lagos')->format('j M \a\t H:i').'. Confirm in the print dialog to print it again.'
            : $person->name.' — confirm in the print dialog to print the badge.';
        return response()->json(['message' => $message, 'name' => $person->name, 'reprint' => (bool) $reprint, 'print_url' => url('/badges/print-station/'.$badge->getRouteKey())])->header('Cache-Control', 'private, no-store');
    }

    public function artwork(Badge $badge, BadgeService $badges)
    {
        $this->admin();
        abort_unless($badge->programme_id === Access::programme()->id, 404);
        return response()->view('badges.artwork', $badges->data(collect([$badge])))->header('Cache-Control', 'private, no-store');
    }
}
