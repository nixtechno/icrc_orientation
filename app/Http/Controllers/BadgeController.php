<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Models\Facilitator;
use App\Models\User;
use App\Services\Access;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BadgeController extends Controller
{
    private function admin(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
    }

    private function scope(Badge $badge): void
    {
        $this->admin();
        abort_unless($badge->programme_id === Access::programme()->id, 404);
    }

    public function index(Request $r)
    {
        $this->admin();
        $r->validate(['q' => 'nullable|string|max:255', 'category' => 'nullable|in:admin,facilitator,staff', 'state' => 'nullable|in:printed,collected,materials,pending']);
        $users = User::whereIn('role', User::ROLES)->whereHas('programmes', fn ($q) => $q->where('programmes.id', Access::programme()->id));
        $facilitators = Facilitator::where('programme_id', Access::programme()->id)->whereNull('user_id');
        if ($r->filled('category')) {
            $users->where('role', $r->category);
            if ($r->category !== 'facilitator') $facilitators->whereRaw('1=0');
        }
        if ($r->filled('q')) {
            $users->where('name', 'like', '%'.$r->q.'%');
            $facilitators->where('name', 'like', '%'.$r->q.'%');
        }

        $issued = Badge::where('programme_id', Access::programme()->id);
        if ($r->filled('q')) $issued->where(fn ($q) => $q->where('snapshot->name', 'like', '%'.$r->q.'%')->orWhere('collection_code', 'like', '%'.$r->q.'%'));
        if ($r->filled('category')) $issued->where('category', $r->category);
        if ($r->filled('state')) match ($r->state) {
            'printed' => $issued->whereNotNull('printed_at'), 'collected' => $issued->whereNotNull('collected_at'),
            'materials' => $issued->whereNotNull('materials_collected_at'), 'pending' => $issued->whereNull('printed_at'),
        };
        $profiles = $users->get()->map(fn ($person) => ['person' => $person, 'key' => 'u:'.$person->id, 'role' => $person->role])
            ->concat($facilitators->get()->map(fn ($person) => ['person' => $person, 'key' => 'f:'.$person->id, 'role' => 'facilitator']))->sortBy(fn ($row) => mb_strtolower($row['person']->name))->values();
        $page = max(1, $r->integer('profiles_page', 1));
        $profiles = new \Illuminate\Pagination\LengthAwarePaginator($profiles->forPage($page, 10)->values(), $profiles->count(), 10, $page, ['path' => url('/badges'), 'pageName' => 'profiles_page']);
        $profiles->withQueryString();
        $existing = Badge::where('programme_id', Access::programme()->id)->whereIn('subject_key', collect($profiles->items())->pluck('key'))->get()->keyBy('subject_key');
        return view('badges.index', ['profiles' => $profiles, 'existing' => $existing, 'badges' => $issued->latest()->paginate(10, ['*'], 'badges_page')->withQueryString(), 'p' => Access::programme(), 'design' => app(\App\Services\BadgeService::class)->data(collect())['settings']]);
    }

    public function settings(Request $r)
    {
        $this->admin();
        $v = $r->validate(['width_mm' => 'required|numeric|between:60,200', 'height_mm' => 'required|numeric|between:80,200', 'layout' => 'nullable|in:folded,single', 'label' => 'required|string|max:200', 'footer' => 'nullable|string|max:160', 'accent' => 'required|regex:/^#[0-9a-fA-F]{6}$/']);
        $p = Access::programme();
        $p->update(['settings' => [...($p->settings ?? []), 'badge' => $v]]);
        Access::audit('badge.design.update', (string) $p->id, null, $v);

        return back()->with('success', 'Badge design saved. Preview the PDF before printing.');
    }

    public function generate(Request $r)
    {
        $this->admin();
        if ($r->filled('single_subject')) $r->merge(['subjects' => [$r->input('single_subject')]]);
        $r->validate(['subjects' => 'required|array|min:1|max:100', 'subjects.*' => 'required|string|regex:/^[uf]:[0-9]+$/']);
        $ids = DB::transaction(function () use ($r) {
            $ids = [];
            foreach (array_unique($r->subjects) as $key) {
                [$type,$id] = explode(':', $key);
                $person = $type === 'u' ? User::whereIn('role', User::ROLES)->whereHas('programmes', fn ($q) => $q->where('programmes.id', Access::programme()->id))->lockForUpdate()->findOrFail($id) : Facilitator::where('programme_id', Access::programme()->id)->lockForUpdate()->findOrFail($id);
                $category = $type === 'f' ? 'facilitator' : $person->role;
                $snapshot = app(\App\Services\BadgeService::class)->snapshot($person, $type);
                $badge = Badge::where('programme_id', Access::programme()->id)->where('subject_key', $key)->lockForUpdate()->first();
                $before = $badge?->toArray();
                $badge ??= new Badge(['programme_id' => Access::programme()->id, 'subject_key' => $key, 'version' => 0]);
                $badge->fill(['user_id' => $type === 'u' ? $person->id : null, 'facilitator_id' => $type === 'f' ? $person->id : null, 'category' => $category, 'snapshot' => $snapshot, 'version' => $badge->version + 1, 'generated_at' => now(), 'printed_at' => null, 'collected_at' => null, 'generated_by' => auth()->id()])->save();
                Access::audit('badge.generate', (string) $badge->id, $before, ['category' => $category, 'version' => $badge->version]);
                $ids[] = $badge->getRouteKey();
            }

return $ids;
        });

        return redirect(count($ids) === 1 ? '/badges/'.$ids[0].'/edit' : '/badges/batch?'.http_build_query(['ids' => $ids]))->with('success', 'Badges generated. Review before printing.');
    }

    private function data($badges): array
    {
        return app(\App\Services\BadgeService::class)->data($badges, request());
    }

    public function batch(Request $r)
    {
        $this->admin();
        $r->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|string|max:120']);
        $badges = Badge::where('programme_id', Access::programme()->id)->where(function ($query) use ($r) {
            $query->whereIn('slug', $r->ids)->orWhereIn('id', array_filter($r->ids, fn ($key) => ctype_digit((string) $key)));
        })->get();
        abort_unless($badges->count() === count(array_unique($r->ids)), 404);
        if ($r->boolean('pdf')) {
            $data = $this->data($badges);
            $height = $data['settings']['height_mm'] * ($data['settings']['layout'] === 'folded' ? 2 : 1);
            $bytes = app(PdfService::class)->render(view('badges.artwork', $data)->render(), [0, 0, $data['settings']['width_mm'] * 72 / 25.4, $height * 72 / 25.4]);
            Access::audit('badge.pdf', implode(',', $badges->pluck('id')->all()));

            return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="icrc-badges.pdf"', 'Cache-Control' => 'private, no-store']);
        }

return view('badges.preview', ['badges' => $badges, 'data' => $this->data($badges)]);
    }

    public function printed(Request $r)
    {
        $this->admin();
        $r->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer']);
        $badges = Badge::where('programme_id', Access::programme()->id)->whereIn('id', $r->ids)->get();
        abort_unless($badges->count() === count(array_unique($r->ids)), 404);
        foreach ($badges as $b) {
            $b->update(['printed_at' => now()]);
            Access::audit('badge.print_confirmed',(string) $b->id);
        }

return redirect('/badges')->with('success','Printing status recorded.');
    }

    public function edit(Request $r, Badge $badge)
    {
        $this->scope($badge);
        $neighbours = Badge::where('programme_id', $badge->programme_id);
        $previous = (clone $neighbours)->where('id', '<', $badge->id)->orderByDesc('id')->first();
        $next = (clone $neighbours)->where('id', '>', $badge->id)->orderBy('id')->first();
        $person = $badge->user_id ? User::findOrFail($badge->user_id) : Facilitator::findOrFail($badge->facilitator_id);
        $type = $badge->user_id ? 'u' : 'f';
        $profile = app(\App\Services\BadgeService::class)->snapshot($person, $type);
        $data = $this->data(collect([$badge]));
        return response()->view('badges.editor', compact('badge', 'data', 'previous', 'next', 'person', 'profile', 'type'))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $r, Badge $badge)
    {
        $this->scope($badge);
        $data = $r->validate(['first_name' => 'required|string|max:100', 'last_name' => 'nullable|string|max:100', 'designation' => 'nullable|string|max:255', 'organisation' => 'nullable|string|max:255']);
        DB::transaction(function () use ($badge, $data) {
            $locked = Badge::whereKey($badge->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();
            if ($locked->user_id) {
                $person = User::whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
                abort_unless($person->programmes()->where('programmes.id', $locked->programme_id)->exists(), 404);
                $profile = $person->profile ?? [];
                $profile['first_name'] = $data['first_name'];
                $profile['surname'] = $data['last_name'] ?? null;
                $profile['organisation'] = $data['organisation'] ?? null;
                $person->profile = $profile;
                $person->name = trim(implode(' ', array_filter([$data['first_name'], $profile['middle_name'] ?? null, $data['last_name'] ?? null])));
                $person->designation = $data['designation'] ?? null;
                $person->save();
                app(\App\Services\ProfileService::class)->syncFacilitator($person);
                if ($person->role === 'facilitator') Facilitator::where('user_id', $person->id)->update(['name' => $person->name]);
                $snapshot = app(\App\Services\BadgeService::class)->snapshot($person, 'u');
            } else {
                $person = Facilitator::whereKey($locked->facilitator_id)->where('programme_id', $locked->programme_id)->lockForUpdate()->firstOrFail();
                $person->fill(['name' => trim($data['first_name'].' '.($data['last_name'] ?? '')), 'job_title' => $data['designation'] ?? null, 'organisation' => $data['organisation'] ?? null])->save();
                $snapshot = app(\App\Services\BadgeService::class)->snapshot($person, 'f');
                $snapshot['first_name'] = $data['first_name']; $snapshot['last_name'] = $data['last_name'] ?? '';
            }
            if ($locked->snapshot !== $snapshot) {
                $locked->fill(['snapshot' => $snapshot, 'category' => $snapshot['category'], 'version' => $locked->version + 1, 'printed_at' => null, 'collected_at' => null, 'generated_at' => now(), 'generated_by' => auth()->id()])->save();
                Access::audit('badge.profile_updated', (string) $locked->id, $before, $locked->toArray());
            }
        });
        return back()->with('success', 'Profile and badge updated. Review the revised badge before printing.');
    }

    public function collection(Request $r, Badge $badge)
    {
        $this->scope($badge);
        $r->validate(['badge_printed' => 'sometimes|boolean', 'badge_collected' => 'sometimes|boolean', 'materials_collected' => 'sometimes|boolean']);
        if ($r->boolean('badge_collected') && ! $r->boolean('badge_printed')) return back()->withErrors(['badge_collected' => 'Confirm the badge is printed before recording badge collection.']);
        DB::transaction(function () use ($r, $badge) {
            $locked = Badge::whereKey($badge->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();
            foreach (['badge_printed' => 'printed_at', 'badge_collected' => 'collected_at', 'materials_collected' => 'materials_collected_at'] as $key => $field) $locked->$field = $r->boolean($key) ? ($locked->$field ?? now()) : null;
            if ($locked->isDirty()) {
                $locked->save();
                Access::audit('badge.collection_updated', (string) $locked->id, $before, $locked->toArray());
            }
        });
        return back()->with('success', 'Printing and collection records saved.');
    }

    public function verify(Badge $badge)
    {
        $this->scope($badge);
        return response()->view('badges.verify', ['badge' => $badge])->header('Cache-Control', 'private, no-store');
    }
}
