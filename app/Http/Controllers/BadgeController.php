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
        $users = User::whereIn('role', ['staff', 'supervisor', 'facilitator'])->whereHas('programmes', fn ($q) => $q->where('programmes.id', Access::programme()->id));
        $facilitators = Facilitator::where('programme_id', Access::programme()->id);
        if ($r->filled('q')) {
            $users->where('name', 'like', '%'.$r->q.'%');
            $facilitators->where('name', 'like', '%'.$r->q.'%');
        }

return view('badges.index', ['users' => $users->orderBy('name')->get(), 'facilitators' => $facilitators->orderBy('name')->get(), 'badges' => Badge::where('programme_id', Access::programme()->id)->latest()->get(), 'p' => Access::programme()]);
    }

    public function settings(Request $r)
    {
        $this->admin();
        $v = $r->validate(['width_mm' => 'required|numeric|between:60,200', 'height_mm' => 'required|numeric|between:80,200', 'label' => 'required|string|max:200', 'footer' => 'nullable|string|max:160', 'accent' => 'required|regex:/^#[0-9a-fA-F]{6}$/']);
        $p = Access::programme();
        $p->update(['settings' => [...($p->settings ?? []), 'badge' => $v]]);
        Access::audit('badge.design.update', (string) $p->id, null, $v);

        return back()->with('success', 'Badge design saved. Preview the PDF before printing.');
    }

    public function generate(Request $r)
    {
        $this->admin();
        $r->validate(['subjects' => 'required|array|min:1|max:100', 'subjects.*' => 'required|string|regex:/^[uf]:[0-9]+$/']);
        $ids = DB::transaction(function () use ($r) {
            $ids = [];
            foreach (array_unique($r->subjects) as $key) {
                [$type,$id] = explode(':', $key);
                $person = $type === 'u' ? User::whereIn('role', ['staff', 'supervisor', 'facilitator'])->whereHas('programmes', fn ($q) => $q->where('programmes.id', Access::programme()->id))->findOrFail($id) : Facilitator::where('programme_id', Access::programme()->id)->findOrFail($id);
                $category = $type === 'f' ? 'facilitator' : $person->role;
                $snapshot = ['name' => $person->name, 'department' => $type === 'f' ? $person->organisation : $person->department, 'photo_path' => $person->photo_path, 'category' => $category, 'programme' => Access::programme()->title];
                $badge = Badge::where('programme_id', Access::programme()->id)->where('subject_key', $key)->lockForUpdate()->first();
                $before = $badge?->toArray();
                $badge ??= new Badge(['programme_id' => Access::programme()->id, 'subject_key' => $key, 'version' => 0]);
                $badge->fill(['user_id' => $type === 'u' ? $person->id : null, 'facilitator_id' => $type === 'f' ? $person->id : null, 'category' => $category, 'snapshot' => $snapshot, 'version' => $badge->version + 1, 'generated_at' => now(), 'printed_at' => null, 'generated_by' => auth()->id()])->save();
                Access::audit('badge.generate', (string) $badge->id, $before, ['category' => $category, 'version' => $badge->version]);
                $ids[] = $badge->id;
            }

return $ids;
        });

        return redirect('/badges/batch?'.http_build_query(['ids' => $ids]))->with('success', 'Badges generated. Review before printing.');
    }

    private function data($badges): array
    {
        $p = Access::programme();
        $settings = $p->settings['badge'] ?? ['width_mm' => 86, 'height_mm' => 110, 'label' => $p->title, 'footer' => '2026 Staff Orientation Programme', 'accent' => '#075332'];
        $logo = PdfService::image(public_path('brand/ICRC_Logo.png'));
        $items = $badges->map(function ($b) {
            $photo = ($b->snapshot['photo_path'] ?? null) ? PdfService::image(Storage::disk('local')->path($b->snapshot['photo_path'])) : null;

            return ['badge' => $b, 'photo' => $photo];
        });

        return compact('items', 'settings', 'logo');
    }

    public function batch(Request $r)
    {
        $this->admin();
        $r->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer']);
        $badges = Badge::where('programme_id', Access::programme()->id)->whereIn('id', $r->ids)->get();
        abort_unless($badges->count() === count(array_unique($r->ids)), 404);
        if ($r->boolean('pdf')) {
            $data = $this->data($badges);
            $bytes = app(PdfService::class)->render(view('badges.artwork', $data)->render(), [0, 0, $data['settings']['width_mm'] * 72 / 25.4, $data['settings']['height_mm'] * 72 / 25.4]);
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
}
