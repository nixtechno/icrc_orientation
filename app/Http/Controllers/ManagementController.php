<?php

namespace App\Http\Controllers;

use App\Models\ProgrammeSession;
use App\Notifications\ProgrammeUpdate;
use App\Services\Access;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ManagementController extends Controller
{
    public const PERMISSIONS = ['staff', 'schedule', 'attendance', 'resources', 'announcements', 'assessments', 'reports', 'qa', 'facilitators', 'qa_answer'];

    public function resources(Request $r)
    {
        $manager = $r->user()->canManage('resources');
        $q = DB::table('resources')->where('programme_id', Access::programme()->id);
        if (! $manager) {
            $q->where('published', true);
        }if ($r->filled('week')) {
            $q->where('week', $r->week);
        }if ($r->filled('category')) {
            $q->where('category', $r->category);
        }

return view('resources', ['items' => $q->latest()->get(), 'manager' => $manager]);
    }

    public function saveResource(Request $r)
    {
        Access::manage('resources');
        $v = $r->validate(['title' => 'required|string|max:255', 'category' => 'nullable|string|max:100', 'week' => 'nullable|integer|between:1,6', 'url' => 'nullable|url:http,https|max:2000', 'file' => 'required_without:url|nullable|file|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,csv,jpg,jpeg,png|max:10240']);
        $path = $r->file('file')?->store('resources', 'local');
        DB::table('resources')->insert(['programme_id' => Access::programme()->id, 'uploaded_by' => auth()->id(), 'title' => $v['title'], 'category' => $v['category'] ?? null, 'week' => $v['week'] ?? null, 'url' => $v['url'] ?? null, 'path' => $path, 'filename' => $r->file('file')?->getClientOriginalName(), 'published' => $r->boolean('published'), 'created_at' => now(), 'updated_at' => now()]);
        Access::audit('resource.create', $v['title']);

        return back()->with('success', 'Resource saved.');
    }

    public function downloadResource(int $id)
    {
        $item = DB::table('resources')->where('programme_id', Access::programme()->id)->find($id);
        abort_unless($item && ($item->published || auth()->user()->canManage('resources')), 403);
        abort_unless($item->path, 404);

        return Storage::disk('local')->download($item->path, $item->filename, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function toggleResource(int $id)
    {
        Access::manage('resources');
        $item = DB::table('resources')->where('programme_id', Access::programme()->id)->find($id);
        abort_unless($item, 404);
        DB::table('resources')->where('id', $id)->update(['published' => ! $item->published, 'updated_at' => now()]);
        Access::audit('resource.publication', (string) $id);

        return back()->with('success', 'Resource publication updated.');
    }

    public function announcements()
    {
        $manager = auth()->user()->canManage('announcements');
        $q = DB::table('announcements')->where('programme_id', Access::programme()->id);
        if (! $manager) {
            $q->where('archived', false)->where('published_at', '<=', now())->where(fn ($q) => $q->whereNull('department')->orWhere('department', auth()->user()->department))->where(fn ($q) => $q->whereNull('group_name')->orWhere('group_name', auth()->user()->group_name));
        }

return view('announcements', ['items' => $q->latest()->get(), 'manager' => $manager]);
    }

    public function saveAnnouncement(Request $r)
    {
        Access::manage('announcements');
        $v = $r->validate(['title' => 'required|string|max:255', 'body' => 'required|string|max:20000', 'department' => 'nullable|string|max:255', 'group_name' => 'nullable|string|max:255', 'published_at' => 'nullable|date']);
        $id = DB::table('announcements')->insertGetId([...$v, 'programme_id' => Access::programme()->id, 'created_at' => now(), 'updated_at' => now()]);
        Access::audit('announcement.create', (string) $id);
        if (! empty($v['published_at'])) {
            $q = Access::staff()->where('active', true);
            if (! empty($v['department'])) {
                $q->where('department', $v['department']);
            }if (! empty($v['group_name'])) {
                $q->where('group_name', $v['group_name']);
            }foreach ($q->get() as $u) {
                $notification = new ProgrammeUpdate($v['title'], $v['body'], url('/announcements'), Access::programme()->settings['email_notifications'] ?? false);
                $notification->delay(Carbon::parse($v['published_at']));
                $u->notify($notification);
            }
        }

return back()->with('success', 'Announcement saved.');
    }

    public function archiveAnnouncement(int $id)
    {
        Access::manage('announcements');
        DB::table('announcements')->where('programme_id', Access::programme()->id)->where('id', $id)->update(['archived' => true, 'updated_at' => now()]);
        Access::audit('announcement.archive', (string) $id);

        return back()->with('success', 'Announcement archived.');
    }

    public function settings()
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        return view('settings', ['p' => Access::programme()]);
    }

    public function saveSettings(Request $r)
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        $v = $r->validate(['title' => 'required|string|max:255', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on', 'timezone' => 'required|timezone', 'about' => 'required|string|max:10000', 'contact' => 'nullable|string|max:2000', 'venue' => 'nullable|string|max:255', 'privacy_url' => 'nullable|url:http,https', 'lateness_minutes' => 'required|integer|min:0|max:120', 'window_minutes' => 'required|integer|min:1|max:480', 'logo' => 'nullable|image|mimes:png,jpg,jpeg|max:2048']);
        $p = Access::programme();
        $before = $p->toArray();
        $settings = [...($p->settings ?? []), ...collect($v)->except(['title', 'starts_on', 'ends_on', 'timezone', 'logo'])->all(), 'excused_in_denominator' => $r->boolean('excused_in_denominator'), 'email_notifications' => $r->boolean('email_notifications')];
        if ($r->hasFile('logo')) {
            $settings['logo'] = $r->file('logo')->store('branding', 'public');
        }$p->update([...collect($v)->only(['title', 'starts_on', 'ends_on', 'timezone'])->all(), 'settings' => $settings]);
        Access::audit('settings.update', (string) $p->id, $before, $p->toArray());

        return back()->with('success', 'Programme settings saved.');
    }

    public function audit()
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        return view('audit', ['logs' => DB::table('audit_logs')->latest('id')->paginate(50)]);
    }

    public function facilitators()
    {
        Access::manage('schedule');

        return view('facilitators',['facilitators' => ProgrammeSession::whereNotNull('facilitator')->get()->groupBy('facilitator')]);
    }
}
