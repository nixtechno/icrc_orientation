<?php

namespace App\Services;

use App\Models\{Badge, DailyAttendance, ProgrammeDay, User};
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{Cache, DB, Http, Log};
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Keeps attendance recorded on the venue server in step with the live site.
 *
 * Every run the venue server uploads the records it changed, then downloads people, badges, day windows and the
 * live site's attendance. Both copies merge with the same rules, so a record settles to the same values on each.
 */
class AttendanceSync
{
    public const STATUS = 'attendance_sync.status';

    public const CURSOR = 'attendance_sync.cursor';

    public const RECEIVED = 'attendance_sync.received';

    private const BATCH = 200;

    private const PERSON = ['name', 'email', 'role', 'active', 'permissions', 'staff_number', 'department', 'designation', 'group_name', 'slug', 'qr_code', 'print_code'];

    private const WINDOWS = ['attendance_required', 'cancelled', 'checkin_opens', 'checkin_closes', 'late_after', 'checkout_opens', 'checkout_closes'];

    /** @var array<string, ?int> */
    private array $ids = [];

    /** @var array<int, ?string> */
    private array $emails = [];

    public static function venue(): bool
    {
        return filled(config('attendance_sync.url'));
    }

    public static function tokenValid(?string $token): bool
    {
        $expected = (string) config('attendance_sync.token');

        return strlen($expected) >= 32 && is_string($token) && hash_equals($expected, $token);
    }

    /** Live site: everything the venue server needs to scan and print without internet. */
    public function snapshot(?string $since): array
    {
        $programme = Access::programme();
        $cursor = now()->toIso8601String();
        $enrolled = DB::table('enrolments')->where('programme_id', $programme->id)->pluck('user_id')->flip();
        $attendance = DailyAttendance::with('day')->where('programme_id', $programme->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', Carbon::parse($since)->setTimezone(config('app.timezone'))))
            ->orderBy('id')->get();

        return [
            'cursor' => $cursor,
            'programme' => ['starts_on' => $programme->starts_on->toDateString(), 'ends_on' => $programme->ends_on->toDateString()],
            'days' => app(AttendanceService::class)->days()->orderBy('date')->get()
                ->map(fn (ProgrammeDay $day) => ['date' => $day->date->toDateString(), ...$day->only(self::WINDOWS)])->all(),
            'people' => User::orderBy('id')->get()
                ->map(fn (User $user) => [...$this->person($user), 'enrolled' => $enrolled->has($user->id)])->all(),
            'badges' => Badge::where('programme_id', $programme->id)->whereNotNull('user_id')->whereNull('facilitator_id')->orderBy('id')->get()
                ->map(fn (Badge $badge) => [
                    'slug' => $badge->slug, 'email' => $this->email($badge->user_id), 'category' => $badge->category,
                    'snapshot' => $badge->snapshot, 'version' => $badge->version, 'generated_at' => $badge->generated_at?->toIso8601String(),
                    'generated_by' => $this->email($badge->generated_by), 'collection_code' => $badge->collection_code,
                    'printed_at' => $badge->printed_at?->toIso8601String(), 'collected_at' => $badge->collected_at?->toIso8601String(),
                    'materials_collected_at' => $badge->materials_collected_at?->toIso8601String(),
                ])->all(),
            'attendance' => $attendance->map(fn (DailyAttendance $record) => $this->export($record))->all(),
        ];
    }

    /**
     * Live site: merge records sent by the venue server.
     *
     * @return array{records: array<string, ?string>, logs: list<string>}
     */
    public function receive(array $records, array $logs, string $origin): array
    {
        $results = [];
        foreach ($records as $in) {
            $results[$in['uuid']] = $this->import($in);
        }
        $accepted = [];
        foreach ($logs as $log) {
            if (! DB::table('attendance_audit_logs')->where('sync_uuid', $log['uuid'])->exists()) {
                DB::table('attendance_audit_logs')->insert([
                    'programme_id' => Access::programme()->id, 'user_id' => $this->id($log['user'] ?? null), 'actor_id' => $this->id($log['actor'] ?? null),
                    'event' => $log['event'], 'verified' => (bool) $log['verified'], 'reason' => $log['reason'] ?? null,
                    'ip_address' => $log['ip_address'] ?? null, 'user_agent' => $log['user_agent'] ?? null,
                    'created_at' => $this->time($log['created_at']), 'sync_uuid' => $log['uuid'], 'origin' => $origin,
                ]);
            }
            $accepted[] = $log['uuid'];
        }
        // Log-only uploads keep the counts from the last batch of records.
        $previous = Cache::get(self::RECEIVED, []);
        Cache::forever(self::RECEIVED, [
            'at' => now()->toIso8601String(), 'origin' => $origin,
            'records' => $results ? count($results) : ($previous['records'] ?? 0),
            'problems' => $results ? count(array_filter($results)) : ($previous['problems'] ?? 0),
        ]);

        return ['records' => $results, 'logs' => $accepted];
    }

    /** Venue server: one check, upload and download. Safe to call from the scheduler and the Sync now button at once. */
    public function run(): array
    {
        $lock = Cache::lock('attendance_sync.run', 300);
        if (! $lock->get()) {
            return $this->status();
        }
        $previous = Cache::get(self::STATUS, []);
        try {
            $client = $this->client();
            $ping = $client->get('ping')->throw()->json();
            $uploaded = $this->upload($client);
            $downloaded = $this->download($client);
            $status = [
                'online' => true, 'checked_at' => now()->toIso8601String(), 'last_synced_at' => now()->toIso8601String(), 'error' => null,
                'clock_offset' => now()->getTimestamp() - Carbon::parse($ping['time'])->getTimestamp(),
                'uploaded' => $uploaded, 'downloaded' => $downloaded,
            ];
        } catch (Throwable $e) {
            Log::warning('Attendance sync failed: '.$e->getMessage());
            $status = [...$previous, 'online' => false, 'checked_at' => now()->toIso8601String(), 'error' => $this->reason($e)];
        } finally {
            $lock->release();
        }
        Cache::forever(self::STATUS, $status);

        return $this->status();
    }

    public function status(): array
    {
        if (! self::venue()) {
            return ['mode' => 'online', 'received' => Cache::get(self::RECEIVED)];
        }
        $pending = $this->pending();

        return [
            'mode' => 'venue', 'online' => null, 'checked_at' => null, 'last_synced_at' => null, 'error' => null, 'clock_offset' => null,
            ...Cache::get(self::STATUS, []),
            'waiting' => (clone $pending)->count() + DB::table('attendance_audit_logs')->whereNull('synced_at')->count(),
            'problems' => (clone $pending)->whereNotNull('sync_error')->count(),
        ];
    }

    /** Venue server: records changed since the live site last confirmed them. */
    private function pending()
    {
        return DailyAttendance::where(fn ($q) => $q->whereNull('synced_version')->orWhereColumn('synced_version', '<', 'sync_version'));
    }

    private function client(): PendingRequest
    {
        $url = rtrim((string) config('attendance_sync.url'), '/');
        $host = parse_url($url, PHP_URL_HOST);
        throw_unless(parse_url($url, PHP_URL_SCHEME) === 'https' || in_array($host, ['localhost', '127.0.0.1'], true), RuntimeException::class, 'ATTENDANCE_SYNC_URL must be an https address.');
        throw_unless(strlen((string) config('attendance_sync.token')) >= 32, RuntimeException::class, 'ATTENDANCE_SYNC_TOKEN must be at least 32 characters.');

        return Http::baseUrl($url.'/api/attendance-sync')->withToken(config('attendance_sync.token'))
            ->acceptJson()->connectTimeout(5)->timeout(30);
    }

    private function upload(PendingRequest $client): int
    {
        $sent = 0;
        $after = 0;
        do {
            $rows = $this->pending()->with('day')->where('id', '>', $after)->orderBy('id')->limit(self::BATCH)->get();
            if ($rows->isEmpty()) {
                break;
            }
            $after = $rows->last()->id;
            $results = $client->post('upload', ['origin' => config('attendance_sync.origin'), 'records' => $rows->map(fn ($row) => $this->export($row))->all()])
                ->throw()->json('records');
            foreach ($rows as $row) {
                if (is_array($results) && array_key_exists($row->sync_uuid, $results) && $results[$row->sync_uuid] === null) {
                    // A scan saved while this batch was in flight has a higher version and stays waiting.
                    DB::table('daily_attendance')->where('id', $row->id)->update(['synced_version' => $row->sync_version, 'synced_at' => now(), 'sync_error' => null]);
                    $sent++;
                } else {
                    DB::table('daily_attendance')->where('id', $row->id)->update(['sync_error' => mb_substr($results[$row->sync_uuid] ?? 'The live site did not confirm this record.', 0, 500)]);
                }
            }
        } while ($rows->count() === self::BATCH);

        do {
            $logs = DB::table('attendance_audit_logs')->whereNull('synced_at')->orderBy('id')->limit(self::BATCH * 2)->get();
            if ($logs->isEmpty()) {
                break;
            }
            $logs->whereNull('sync_uuid')->each(fn ($log) => DB::table('attendance_audit_logs')->where('id', $log->id)->update(['sync_uuid' => $log->sync_uuid = (string) Str::uuid()]));
            $accepted = $client->post('upload', ['origin' => config('attendance_sync.origin'), 'records' => [], 'logs' => $logs->map(fn ($log) => [
                'uuid' => $log->sync_uuid, 'user' => $this->email($log->user_id), 'actor' => $this->email($log->actor_id), 'event' => $log->event,
                'verified' => (bool) $log->verified, 'reason' => $log->reason, 'ip_address' => $log->ip_address, 'user_agent' => $log->user_agent,
                'created_at' => Carbon::parse($log->created_at, config('app.timezone'))->toIso8601String(),
            ])->values()->all()])->throw()->json('logs');
            $done = DB::table('attendance_audit_logs')->whereIn('sync_uuid', (array) $accepted)->update(['synced_at' => now()]);
        } while ($done && $logs->count() === self::BATCH * 2);

        return $sent;
    }

    private function download(PendingRequest $client): int
    {
        $data = $client->get('snapshot', array_filter(['since' => Cache::get(self::CURSOR)]))->throw()->json();
        $programme = Access::programme();
        DB::transaction(function () use ($data, $programme) {
            $programme->update(Arr::only($data['programme'], ['starts_on', 'ends_on']));
            foreach ($data['days'] as $day) {
                app(AttendanceService::class)->days()->whereDate('date', $day['date'])->first()?->update(Arr::only($day, self::WINDOWS));
            }
            foreach ($data['people'] as $person) {
                $this->savePerson($person, $programme->id);
            }
            foreach ($data['badges'] as $badge) {
                $this->saveBadge($badge, $programme->id);
            }
        });
        $this->ids = [];
        $merged = 0;
        foreach ($data['attendance'] as $in) {
            $merged += $this->import($in, true) === null ? 1 : 0;
        }
        Cache::forever(self::CURSOR, $data['cursor']);

        return $merged;
    }

    private function savePerson(array $person, int $programmeId): void
    {
        $user = User::where('email', $person['email'])->first()
            ?? ($person['qr_code'] ? User::where('qr_code', $person['qr_code'])->first() : null)
            ?? (new User)->forceFill(['password' => Str::random(40)]); // People added from the live site cannot sign in here.
        $user->forceFill(Arr::only($person, self::PERSON))->save();
        $enrolled = $user->programmes()->where('programmes.id', $programmeId)->exists();
        if ($person['enrolled'] && ! $enrolled) {
            $user->programmes()->attach($programmeId);
        } elseif (! $person['enrolled'] && $enrolled) {
            $user->programmes()->detach($programmeId);
        }
    }

    private function saveBadge(array $in, int $programmeId): void
    {
        $owner = $this->id($in['email']);
        if (! $owner) {
            return;
        }
        $badge = Badge::where('slug', $in['slug'])->first()
            ?? Badge::where('programme_id', $programmeId)->where('subject_key', 'u:'.$owner)->first()
            ?? new Badge(['programme_id' => $programmeId]);
        // Printing and collection at the venue are kept; the earliest time wins.
        $earliest = fn (string $field) => collect([$badge->$field, $in[$field] ? $this->time($in[$field]) : null])->filter()->min();
        $badge->forceFill([
            'subject_key' => 'u:'.$owner, 'user_id' => $owner, 'facilitator_id' => null, 'slug' => $in['slug'], 'category' => $in['category'],
            'snapshot' => $in['snapshot'], 'version' => $in['version'], 'generated_at' => $this->time($in['generated_at']),
            'generated_by' => $badge->generated_by ?? $this->id($in['generated_by']) ?? $owner,
            'collection_code' => $in['collection_code'] ?? $badge->collection_code,
            'printed_at' => $earliest('printed_at'), 'collected_at' => $earliest('collected_at'), 'materials_collected_at' => $earliest('materials_collected_at'),
        ])->save();
    }

    /**
     * Merge one record from the other copy into this one. Returns null on success, or why it could not be placed.
     * Records downloaded from the live site are marked as confirmed unless this copy has unsent changes of its own.
     */
    public function import(array $in, bool $fromLive = false): ?string
    {
        $user = (filled($in['qr_code'] ?? null) ? User::where('qr_code', $in['qr_code'])->first() : null)
            ?? (filled($in['email'] ?? null) ? User::where('email', $in['email'])->first() : null);
        if (! $user) {
            return 'This person ('.($in['email'] ?? 'unknown').') is not on '.($fromLive ? 'the venue server' : 'the live site').'.';
        }
        $day = app(AttendanceService::class)->days()->whereDate('date', $in['date'])->first();
        if (! $day) {
            return 'There is no programme day on '.$in['date'].'.';
        }
        try {
            DB::transaction(function () use ($in, $user, $day, $fromLive) {
                $programmeId = Access::programme()->id;
                $record = DailyAttendance::where('programme_id', $programmeId)->where('programme_day_id', $day->id)->where('user_id', $user->id)->lockForUpdate()->first();
                $confirmed = ! $record || ($record->synced_version !== null && $record->synced_version >= $record->sync_version);
                $record ??= new DailyAttendance([
                    'programme_id' => $programmeId, 'programme_day_id' => $day->id, 'user_id' => $user->id,
                    'sync_uuid' => $in['uuid'], 'origin' => $in['origin'] ?? null, 'staff_name' => $in['staff_name'] ?? $user->name,
                    'staff_number_snapshot' => $in['staff_number'] ?? $user->staff_number, 'attendance_date' => $day->date,
                    'recorded_by' => $this->id($in['recorded_by'] ?? null), 'notes' => $in['notes'] ?? null,
                ]);
                $this->merge($record, $in, $day);
                $record->save();
                if ($fromLive && $confirmed) {
                    DB::table('daily_attendance')->where('id', $record->id)->update(['synced_version' => $record->sync_version, 'synced_at' => now(), 'sync_error' => null]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Attendance sync could not merge '.$in['uuid'].': '.$e->getMessage());

            return 'This record could not be saved yet; it will be retried.';
        }

        return null;
    }

    private function merge(DailyAttendance $record, array $in, ProgrammeDay $day): void
    {
        foreach (['in' => ['checked_in_at', 'signin'], 'out' => ['checked_out_at', 'signout']] as $direction => [$column, $prefix]) {
            if (empty($in[$column])) {
                continue;
            }
            $time = $this->time($in[$column]);
            $verified = (bool) ($in[$prefix.'_device_verified'] ?? false);
            $current = $record->$column;
            // A device-verified time beats a scan; otherwise the earliest sign-in and the latest sign-out win.
            $wins = ! $current || ($verified !== (bool) $record->{$prefix.'_device_verified'}
                ? $verified
                : ($direction === 'in' ? $time->lt($current) : $time->gt($current)));
            if (! $wins) {
                continue;
            }
            $record->forceFill([
                $column => $time, $prefix.'_method' => $in[$prefix.'_method'] ?? null,
                $prefix.'_scanned_by' => $this->id($in[$prefix.'_scanned_by'] ?? null),
                $prefix.'_device_verified' => $verified, $prefix.'_location_verified' => (bool) ($in[$prefix.'_location_verified'] ?? false),
                // Device ids and encrypted locations stay on the copy that verified them.
                $prefix.'_device_id' => null, $prefix.'_location' => null,
            ]);
        }
        if ($record->checked_in_at && in_array($record->status, [null, 'pending', 'present', 'late'], true)) {
            $late = app(AttendanceService::class)->time($day, 'late_after');
            if ($late) {
                $record->status = $record->checked_in_at->gt($late) ? 'late' : 'present';
                $record->punctuality_status = $record->checked_in_at->gt($late) ? 'Late' : 'On Time';
            } else {
                $record->status ??= $in['status'] ?? null;
                $record->punctuality_status ??= $in['punctuality_status'] ?? null;
            }
        } elseif (! $record->status) {
            $record->status = $in['status'] ?? null;
        }
        if ($record->checked_in_at && $record->checked_out_at) {
            $record->duration_seconds = max(0, (int) $record->checked_in_at->diffInSeconds($record->checked_out_at));
        }
    }

    private function export(DailyAttendance $record): array
    {
        $user = $record->user_id ? User::find($record->user_id) : null;

        return [
            'uuid' => $record->sync_uuid, 'origin' => $record->origin, 'email' => $user?->email, 'qr_code' => $user?->qr_code,
            'date' => $record->day->date->toDateString(), 'staff_name' => $record->staff_name, 'staff_number' => $record->staff_number_snapshot,
            'status' => $record->status, 'punctuality_status' => $record->punctuality_status, 'notes' => $record->notes,
            'recorded_by' => $this->email($record->recorded_by),
            'checked_in_at' => $record->checked_in_at?->toIso8601String(), 'checked_out_at' => $record->checked_out_at?->toIso8601String(),
            ...collect(['signin', 'signout'])->flatMap(fn ($prefix) => [
                $prefix.'_method' => $record->{$prefix.'_method'}, $prefix.'_scanned_by' => $this->email($record->{$prefix.'_scanned_by'}),
                $prefix.'_device_verified' => (bool) $record->{$prefix.'_device_verified'}, $prefix.'_location_verified' => (bool) $record->{$prefix.'_location_verified'},
            ])->all(),
        ];
    }

    private function person(User $user): array
    {
        return [...Arr::only($user->getAttributes(), self::PERSON), 'active' => (bool) $user->active, 'permissions' => $user->permissions, 'slug' => $user->getRouteKey()];
    }

    private function time(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    private function id(?string $email): ?int
    {
        if (! $email) {
            return null;
        }

        return $this->ids[$email] ??= User::where('email', $email)->value('id');
    }

    private function email(?int $id): ?string
    {
        if (! $id) {
            return null;
        }

        return $this->emails[$id] ??= User::whereKey($id)->value('email');
    }

    private function reason(Throwable $e): string
    {
        return match (true) {
            $e instanceof \Illuminate\Http\Client\ConnectionException => 'The live site could not be reached. Attendance is saved here and will upload when the internet is back.',
            $e instanceof \Illuminate\Http\Client\RequestException && $e->response->status() === 401 => 'The live site rejected the sync token. Check ATTENDANCE_SYNC_TOKEN on both copies.',
            $e instanceof \Illuminate\Http\Client\RequestException => 'The live site returned an error ('.$e->response->status().'). Sync will retry.',
            default => mb_substr($e->getMessage(), 0, 300),
        };
    }
}
