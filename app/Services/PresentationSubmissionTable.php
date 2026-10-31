<?php

namespace App\Services;

use App\Models\{Activity, Submission};
use Illuminate\Http\Request;

class PresentationSubmissionTable
{
    public function data(Request $request): array
    {
        Access::manage('assessments');
        $request->validate([
            'q' => 'nullable|string|max:255', 'department' => 'nullable|string|max:255',
            'presentation' => 'nullable|string|max:120', 'review' => 'nullable|in:ungraded,graded,released',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d'.($request->filled('from')?'|after_or_equal:from':''),
            'per_page' => 'sometimes|integer|in:10,25,50,100', 'submissions_page' => 'sometimes|integer|min:1',
            'sort' => 'sometimes|in:submitted,staff,presentation,score', 'direction' => 'sometimes|in:asc,desc',
        ]);
        $query = Submission::query()->join('users as presenter', 'presenter.id', '=', 'submissions.user_id')
            ->join('activities as presentation', 'presentation.id', '=', 'submissions.activity_id')
            ->where('presentation.programme_id', Access::programme()->id)->where('presentation.kind', 'presentation')
            ->where('presenter.role', 'staff')->whereNotNull('submissions.submitted_at')->select('submissions.*')
            ->with('user', 'activity.group');
        if ($request->filled('q')) $query->where(function ($query) use ($request) {
            foreach (['presenter.name','presenter.staff_number','presentation.title','submissions.filename'] as $column) $query->orWhere($column, 'like', '%'.$request->q.'%');
        });
        if ($request->filled('department')) $query->where('presenter.department', $request->department);
        if ($request->filled('presentation')) $query->where('presentation.slug', $request->presentation);
        if ($request->filled('from')) $query->whereDate('submissions.submitted_at', '>=', $request->from);
        if ($request->filled('to')) $query->whereDate('submissions.submitted_at', '<=', $request->to);
        if ($request->filled('review')) match ($request->review) {
            'ungraded' => $query->whereNull('submissions.score')->whereNull('submissions.released_at'),
            'graded' => $query->whereNotNull('submissions.score')->whereNull('submissions.released_at'),
            'released' => $query->whereNotNull('submissions.released_at'),
        };
        $sorts = ['submitted'=>'submissions.submitted_at','staff'=>'presenter.name','presentation'=>'presentation.title','score'=>'submissions.score'];
        $submissions = $query->orderBy($sorts[$request->input('sort','submitted')], $request->input('direction','desc'))
            ->orderByDesc('submissions.id')->paginate($request->integer('per_page',10), ['submissions.*'], 'submissions_page')->withQueryString();
        $departments = Access::staff()->whereNotNull('department')->where('department','!=','')->distinct()->orderBy('department')->pluck('department');
        $presentations = Activity::where('programme_id',Access::programme()->id)->where('kind','presentation')->orderBy('title')->get();
        return compact('submissions','departments','presentations');
    }
}
