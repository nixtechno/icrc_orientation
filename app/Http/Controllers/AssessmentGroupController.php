<?php

namespace App\Http\Controllers;

use App\Models\AssessmentGroup;
use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AssessmentGroupController extends Controller
{
    public function index(?AssessmentGroup $group = null)
    {
        Access::manage('assessments');
        if ($group) abort_unless($group->programme_id === Access::programme()->id, 404);
        return view('assessment-groups', [
            'groups' => AssessmentGroup::where('programme_id', Access::programme()->id)->withCount('members')->orderBy('name')->get(),
            'editingGroup' => $group?->load('members'),
            'staff' => Access::staff()->orderBy('name')->get(),
        ]);
    }

    public function save(Request $request, ?AssessmentGroup $group = null)
    {
        Access::manage('assessments');
        $programmeId = Access::programme()->id;
        if ($group) abort_unless($group->programme_id === $programmeId, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('assessment_groups')->where('programme_id', $programmeId)->ignore($group?->id)],
            'members' => 'required|array|min:1|max:5000',
            'members.*' => ['required', 'integer', 'distinct', Rule::in(Access::staff()->pluck('users.id')->all())],
        ]);
        DB::transaction(function () use ($data, $programmeId, &$group) {
            $before = $group?->load('members')->toArray();
            $group ??= new AssessmentGroup(['programme_id' => $programmeId, 'created_by' => auth()->id()]);
            $group->name = $data['name'];
            $group->save();
            $group->members()->sync($data['members']);
            Access::audit('assessment_group.save', (string) $group->id, $before, ['name' => $group->name, 'members' => $data['members']]);
        });
        return redirect('/assessment-groups')->with('success', 'Group saved. Existing activity participants remain unchanged.');
    }
}
