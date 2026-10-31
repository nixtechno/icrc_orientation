<?php

namespace App\Http\Controllers;

use App\Mail\ProgrammeInvitation;
use App\Models\User;
use App\Services\Access;
use App\Services\InvitationEmail;
use App\Services\InvitationService;
use App\Services\ProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PeopleController extends Controller
{
    public const PERMISSIONS = ['staff', 'schedule', 'attendance', 'resources', 'announcements', 'assessments', 'reports', 'qa', 'facilitators', 'qa_answer'];

    public function allowed(User $u): void
    {
        abort_unless($this->manages($u), 403);
    }

    private function manages(User $u): bool
    {
        return $u->programmes()->where('programmes.id', Access::programme()->id)->exists() && (auth()->user()->role === 'admin' || $u->role === 'staff');
    }

    public function index(Request $r)
    {
        Access::manage('staff');
        $q = User::whereHas('programmes', fn ($q) => $q->where('programmes.id', Access::programme()->id));
        if ($r->user()->role !== 'admin') {
            $q->where('role', 'staff');
        }if ($r->filled('q')) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('email', 'like', '%'.$r->q.'%')->orWhere('staff_number', 'like', '%'.$r->q.'%'));
        }if ($r->filled('role')) {
            $q->where('role', $r->role);
        }$people = $q->orderBy('name')->get();
        $invitations = DB::table('invitations')->whereIn('user_id', $people->pluck('id'))->orderByDesc('id')->get()->unique('user_id')->keyBy('user_id');
        $invitationEmail = ['content' => InvitationEmail::content(Access::programme()), 'defaults' => InvitationEmail::defaults(), 'placeholders' => InvitationEmail::PLACEHOLDERS];

        return view('people.index', compact('people', 'invitations', 'invitationEmail'));
    }

    public function form(?User $user = null)
    {
        Access::manage('staff');
        if ($user) {
            $this->allowed($user);
        }

return view('people.form', compact('user'));
    }

    public function save(Request $r, ProfileService $profiles, ?User $user = null)
    {
        Access::manage('staff');
        if ($user) {
            $this->allowed($user);
            if ($user->hasLockedInvitationEmail()) $r->merge(['email' => $user->email]);
        }$quick = $r->boolean('invitation_only') && ! $user;
        $rules = $profiles->rules($user, false, is_string($r->input('role')) ? $r->input('role') : null);
        $rules['role'] = ['required', Rule::in($r->user()->role === 'admin' ? User::ROLES : ['staff'])];
        $rules['permissions'] = 'nullable|array';
        $rules['permissions.*'] = Rule::in(self::PERMISSIONS);
        $rules['responsibilities'] = 'nullable|string|max:5000';
        if ($quick) {
            $rules['name'] = 'required|string|max:255';
        } else {
            $rules['first_name'] = 'required|string|max:100';
            $rules['surname'] = 'required|string|max:100';
        }$v = $r->validate($rules);
        $content = $r->filled('subject') ? $r->validate(InvitationEmail::rules()) : null;
        abort_if($user?->id === auth()->id() && (! $r->boolean('active') || $v['role'] !== 'admin'), 422, 'You cannot remove your own administrative access.');
        DB::transaction(function () use ($r, $profiles, &$user, $v) {
            $user ??= new User;
            $before = $user->exists ? $user->toArray() : null;
            $new = ! $user->exists;
            $profiles->assign($user, $v, true, $v['role']);
            $profiles->photo($r, $user);
            $user->role = $v['role'];
            if (auth()->user()->role === 'admin') {
                $user->permissions = $v['permissions'] ?? ($new && $v['role'] === 'facilitator' && ! $r->boolean('permissions_present') ? User::FACILITATOR_DEFAULT_PERMISSIONS : []);
                $user->responsibilities = $v['responsibilities'] ?? null;
            }$user->active = $new ? false : $r->boolean('active');
            if ($new) {
                $user->password = Str::random(64);
            }$user->save();
            $profiles->syncFacilitator($user);
            $user->programmes()->syncWithoutDetaching([Access::programme()->id]);
            Access::audit('user.save', (string) $user->id, $before, $user->toArray());
        });
        if ($quick || $r->boolean('send_invitation')) {
            try {
                app(InvitationService::class)->send($user, $content);
            } catch (\Throwable $e) {
                if (! $r->expectsJson()) {
                    throw $e;
                }
                report($e);

                return response()->json(['message' => 'The account was created, but the invitation could not be sent. Use Send / resend in the people list to try again.'], 500);
            }
        }
        $message = 'Profile saved'.(($quick || $r->boolean('send_invitation')) ? ' and activation invitation sent.' : '.');
        if ($r->expectsJson()) {
            return response()->json(['message' => $message]);
        }

return redirect('/manage/staff')->with('success', $message);
    }

    public function invitationPreview(Request $r)
    {
        Access::manage('staff');
        $content = $r->validate(InvitationEmail::rules());
        $sample = $r->validate(['name' => 'nullable|string|max:255', 'email' => 'nullable|string|max:255', 'role' => ['nullable', Rule::in(User::ROLES)], 'user' => 'nullable|string|max:120']);
        if (filled($sample['user'] ?? null)) {
            // A single existing person: preview exactly what they will receive, including their own QR code.
            $recipient = User::where('slug', $sample['user'])->first();
            abort_unless($recipient && $this->manages($recipient), 404);
        } else {
            // New people and bulk sends: sample details and a placeholder QR code.
            $recipient = (new User)->forceFill(['name' => ($sample['name'] ?? null) ?: 'Sample Recipient', 'email' => ($sample['email'] ?? null) ?: 'recipient@example.org', 'role' => $sample['role'] ?? 'staff']);
        }
        $programme = Access::programme();
        $mail = new ProgrammeInvitation($recipient, $programme, url('/invitation/your-personal-activation-link'), now()->addDays(3), $content);
        $mail->logoSrc = $programme->logoUrl() ?? asset('brand/ICRC_Logo.png');
        $mail->printQrSrc = 'data:image/png;base64,'.base64_encode($mail->printQrPng());

        return response()->json(['subject' => $mail->subjectLine, 'html' => $mail->render()]);
    }

    public function sendInvitations(Request $r, InvitationService $invitations)
    {
        Access::manage('staff');
        $content = $r->validate(InvitationEmail::rules());
        $keys = $r->validate(['users' => 'required|array|min:1|max:25', 'users.*' => 'required|string|max:255'])['users'];
        $people = User::whereIn((new User)->getRouteKeyName(), $keys)->get()->keyBy(fn (User $u) => (string) $u->getRouteKey());
        $results = [];
        foreach (array_unique($keys) as $key) {
            $u = $people->get($key);
            $result = match (true) {
                ! $u || ! $this->manages($u) => ['status' => 'skipped', 'message' => 'Not found or not permitted.'],
                (bool) $u->active => ['status' => 'skipped', 'message' => 'Already activated.'],
                default => null,
            };
            if (! $result) {
                try {
                    $invitations->send($u, $content);
                    $result = ['status' => 'sent', 'message' => 'Invitation sent.'];
                } catch (\Throwable $e) {
                    report($e);
                    $result = ['status' => 'failed', 'message' => 'Delivery failed. Check the mail settings and try again.'];
                }
            }
            $results[] = ['key' => $key, 'name' => $u?->name, ...$result];
        }

        return response()->json(['results' => $results]);
    }

    public function invitationTemplate(Request $r)
    {
        Access::manage('staff');
        abort_unless($r->user()->role === 'admin', 403);
        $content = $r->validate(InvitationEmail::rules());
        $programme = Access::programme();
        $before = InvitationEmail::content($programme);
        InvitationEmail::save($programme, $content);
        Access::audit('invitation_email.update', (string) $programme->id, $before, $content);

        return response()->json(['message' => 'Saved as the default invitation email.']);
    }

    public function invite(User $user)
    {
        Access::manage('staff');
        $this->allowed($user);
        if ($user->role !== 'staff') {
            abort_unless(auth()->user()->role === 'admin', 403);
        }app(InvitationService::class)->send($user);

        return back()->with('success', 'Invitation sent. Local mail is logged until SMTP is configured.');
    }

    public function revoke(User $user)
    {
        Access::manage('staff');
        $this->allowed($user);
        DB::table('invitations')->where('user_id', $user->id)->whereNull('used_at')->update(['revoked_at' => now()]);
        Access::audit('invitation.revoke', (string) $user->id);

        return back()->with('success', 'Outstanding invitations revoked.');
    }

    public function template(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $r->validate(['role' => 'required|in:staff,facilitator', 'format' => 'required|in:csv,xlsx']);
        $headers = ProfileService::importColumns($r->role);
        if ($r->format === 'csv') {
            return response()->streamDownload(function () use ($headers) {
                $h = fopen('php://output', 'w');
                fputcsv($h, $headers);
                fclose($h);
            }, $r->role.'-template.csv', ['Content-Type' => 'text/csv']);
        }$sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray($headers);
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getActiveSheet()->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getActiveSheet()->getColumnDimension($column)->setWidth(23);
        }

return response()->streamDownload(fn () => (new Xlsx($sheet))->save('php://output'), $r->role.'-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function preview(Request $r, ProfileService $profiles)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $r->validate(['role' => 'required|in:staff,facilitator', 'file' => 'required|file|mimes:csv,txt,xlsx|max:2048']);
        $rows = [];
        $issues = [];
        $raw = [];
        $file = $r->file('file');
        if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $info = $reader->listWorksheetInfo($file->getRealPath());
            abort_if(($info[0]['totalRows'] ?? 0) > 1001 || ($info[0]['totalColumns'] ?? 0) > 12, 422, 'Template allows 1000 participants and 12 columns.');
            $book = $reader->load($file->getRealPath());
            $raw = $book->getSheet(0)->toArray(null, false, false, false);
            $book->disconnectWorksheets();
        } else {
            $h = fopen($file->getRealPath(), 'r');
            while (($line = fgetcsv($h)) !== false) {
                $raw[] = $line;
                abort_if(count($raw) > 1001, 422, 'Maximum 1000 participants per import.');
            }fclose($h);
        }
        $header = array_map(fn ($x) => trim(ltrim((string) $x, "\xEF\xBB\xBF")), array_shift($raw) ?? []);
        abort_unless($header === ProfileService::importColumns($r->role) || ($r->role === 'facilitator' && $header === ProfileService::IMPORT_COLUMNS), 422, 'Column headers must match the downloaded template exactly.');
        $seenEmail = [];
        $seenNumber = [];
        foreach ($raw as $i => $row) {
            $number = $i + 2;
            if (! array_filter($row, fn ($x) => $x !== null && $x !== '')) {
                continue;
            }if (count($row) !== count($header)) {
                $issues[$number] = ['Incorrect column count.'];

                continue;
            }$data = array_combine($header, array_map(fn ($x) => $x === null || trim((string) $x) === '' ? null : trim((string) $x), $row));
            $data['email'] = strtolower($data['email'] ?? '');
            if (is_numeric($data['resumption_date'] ?? null) && $data['resumption_date'] > 20000 && $data['resumption_date'] < 90000) {
                $data['resumption_date'] = Date::excelToDateTimeObject((float) $data['resumption_date'])->format('Y-m-d');
            }$rules = $profiles->rules(null, false, $r->role);
            unset($rules['photo']);
            $rules['first_name'] = 'required|string|max:100';
            $rules['surname'] = 'required|string|max:100';
            $validator = Validator::make($data, $rules);
            $errors = $validator->errors()->all();
            foreach ($data as $field => $value) {
                if (is_string($value) && preg_match('/^[=+@]/', $value) && ! (in_array($field, ['phone', 'alternative_phone'], true) && preg_match('/^\+[0-9 ]+$/', $value))) {
                    $errors[] = 'Formula-like values are not allowed in templates.';
                }
            }if (isset($seenEmail[$data['email']])) {
                $errors[] = 'Duplicate email within file; first seen on row '.$seenEmail[$data['email']];
            }$seenEmail[$data['email']] = $number;
            $staffNumber = $r->role === 'facilitator' ? '' : strtolower($data['staff_number'] ?? '');
            if ($staffNumber !== '') {
                if (isset($seenNumber[$staffNumber])) {
                    $errors[] = 'Duplicate staff number within file; first seen on row '.$seenNumber[$staffNumber];
                }$seenNumber[$staffNumber] = $number;
            }if ($errors) {
                $issues[$number] = array_values(array_unique($errors));
            }$rows[] = ['row' => $number, 'data' => $data];
        }
        $id = DB::table('participant_imports')->insertGetId(['user_id' => auth()->id(), 'programme_id' => Access::programme()->id, 'role' => $r->role, 'rows' => json_encode($rows), 'issues' => json_encode($issues), 'created_at' => now(), 'updated_at' => now()]);
        Access::audit('participant_import.preview', (string) $id, null, ['role' => $r->role, 'rows' => count($rows), 'invalid_rows' => count($issues)]);

        return view('people.import', compact('id', 'rows', 'issues'));
    }

    public function confirm(Request $r, ProfileService $profiles)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $r->validate(['import_id' => 'required|integer']);
        $created = [];
        $summary = DB::transaction(function () use ($r, $profiles, &$created) {
            $job = DB::table('participant_imports')->where('id', $r->import_id)->where('user_id', auth()->id())->where('programme_id', Access::programme()->id)->lockForUpdate()->firstOrFail();
            abort_if($job->confirmed_at, 409, 'This import has already been confirmed.');
            $rows = json_decode($job->rows, true);
            $issues = json_decode($job->issues, true);
            $summary = ['created' => 0, 'skipped' => 0, 'failed' => count($issues)];
            foreach ($rows as $row) {
                if (isset($issues[$row['row']])) {
                    continue;
                }$data = $row['data'];
                $v = Validator::make($data, $profiles->rules(null, false, $job->role));
                if ($v->fails()) {
                    $summary['skipped']++;

                    continue;
                }$u = new User;
                $profiles->assign($u, $data, true, $job->role);
                $u->forceFill(['role' => $job->role, 'active' => false, 'permissions' => $job->role === 'facilitator' ? User::FACILITATOR_DEFAULT_PERMISSIONS : [], 'password' => Str::random(64)])->save();
                $u->programmes()->attach($job->programme_id);
                $created[] = $u;
                $summary['created']++;
            }DB::table('participant_imports')->where('id', $job->id)->update(['confirmed_at' => now(), 'summary' => json_encode($summary), 'updated_at' => now()]);
            Access::audit('participant_import.confirm', (string) $job->id, null, $summary);

            return $summary;
        });
        $mailFailures = 0;
        if ($r->boolean('send_invitations')) {
            foreach ($created as $u) {
                try {
                    app(InvitationService::class)->send($u);
                } catch (\Throwable $e) {
                    report($e);
                    $mailFailures++;
                }
            }
        }

return redirect('/manage/staff')->with('success', "Import complete: {$summary['created']} created, {$summary['skipped']} skipped after revalidation, {$summary['failed']} failed validation. Invitation delivery failures: {$mailFailures}.");
    }

    public function photo(User $user)
    {
        Access::enrolled();
        abort_unless($user->id === auth()->id() || auth()->user()->canManage('staff') || auth()->user()->role === 'admin', 403);
        $this->allowedForPhoto($user);
        if ($user->id !== auth()->id()) {
            $this->allowed($user);
        }abort_unless($user->photo_path, 404);

        return Storage::disk('local')->response($user->photo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function allowedForPhoto(User $user): void
    {
        abort_unless($user->programmes()->where('programmes.id', Access::programme()->id)->exists(), 403);
    }

    public function profileSettings(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        if ($r->isMethod('post')) {
            $v = $r->validate(['required_fields' => 'nullable|array', 'required_fields.*' => Rule::in(ProfileService::CONFIGURABLE), 'privacy_notice' => 'required|string|max:10000', 'programme_information_label' => 'nullable|string|max:255']);
            $p = Access::programme();
            $p->update(['settings' => [...($p->settings ?? []), 'required_profile_fields' => $v['required_fields'] ?? [], 'privacy_notice' => $v['privacy_notice'], 'programme_information_label' => $v['programme_information_label'] ?? null]]);
            Access::audit('profile_policy.update',(string) $p->id);

            return back()->with('success','Registration policy saved.');
        }

return view('people.settings',['p' => Access::programme()]);
    }
}
