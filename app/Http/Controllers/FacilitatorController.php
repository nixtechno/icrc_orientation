<?php

namespace App\Http\Controllers;

use App\Models\Facilitator;
use App\Models\ProgrammeSession;
use App\Models\User;
use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FacilitatorController extends Controller
{
    public function index(Request $r)
    {
        Access::manage('facilitators');
        $q = Facilitator::where('programme_id', Access::programme()->id)->with('sessions', 'user');
        if ($r->filled('q')) {
            $q->where('name', 'like', '%'.$r->q.'%');
        }$people = $q->orderBy('name')->get();
        $sessions = ProgrammeSession::whereHas('day.week', fn ($q) => $q->where('programme_id', Access::programme()->id))->with('day')->get();
        $accounts = User::whereHas('programmes', fn ($q) => $q->where('programmes.id', Access::programme()->id))->where('active', true)->get();
        $labels = $sessions->whereNotNull('facilitator')->pluck('facilitator')->unique();

        return view('people.facilitators', compact('people', 'sessions', 'accounts', 'labels'));
    }

    public function save(Request $r, ?Facilitator $facilitator = null)
    {
        Access::manage('facilitators');
        if ($facilitator) {
            abort_unless($facilitator->programme_id === Access::programme()->id, 403);
        }$v = $r->validate(['name' => 'required|string|max:255', 'organisation' => 'nullable|string|max:255', 'job_title' => 'nullable|string|max:255', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40', 'photo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048', 'sessions' => 'nullable|array', 'sessions.*' => 'integer', 'user_id' => 'nullable|integer']);
        $ids = ProgrammeSession::whereHas('day.week', fn ($q) => $q->where('programme_id', Access::programme()->id))->whereIn('id', $v['sessions'] ?? [])->pluck('id');
        abort_unless($ids->count() === count(array_unique($v['sessions'] ?? [])), 422);
        if (! empty($v['user_id'])) {
            abort_unless(auth()->user()->role === 'admin', 403);
            abort_unless(User::whereKey($v['user_id'])->whereHas('programmes', fn ($q) => $q->where('programmes.id', Access::programme()->id))->exists(), 422);
        }DB::transaction(function () use ($r, $v, $ids, &$facilitator) {
            $before = $facilitator?->toArray();
            $facilitator ??= new Facilitator(['programme_id' => Access::programme()->id]);
            $fields = collect($v)->except(['sessions', 'photo'])->all();
            if (auth()->user()->role !== 'admin') {
                unset($fields['user_id']);
            }$facilitator->fill($fields);
            if ($r->hasFile('photo')) {
                $facilitator->photo_path = $r->file('photo')->store('facilitator-photos', 'local');
            }$facilitator->save();
            $facilitator->sessions()->sync($ids);
            Access::audit('facilitator.save', (string) $facilitator->id, $before, $facilitator->toArray());
        });

        return back()->with('success', 'Facilitator profile saved. No login or permission was automatically created.');
    }

    public function photo(Facilitator $facilitator)
    {
        abort_unless($facilitator->programme_id === Access::programme()->id && (auth()->user()->canManage('facilitators') || $facilitator->user_id === auth()->id()), 403);
        abort_unless($facilitator->photo_path, 404);

        return Storage::disk('local')->response($facilitator->photo_path,null,['Cache-Control' => 'private, no-store']);
    }
}
