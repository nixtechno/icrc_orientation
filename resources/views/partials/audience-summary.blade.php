@php($audienceMode=$activity->audienceMode())
@php($staffCount=$count??$activity->participants()->count())
{{ match($audienceMode){'group'=>'Group: '.($activity->group?->name??'Staff group'),'selected'=>$staffCount===1?'1 selected staff member':$staffCount.' selected staff',default=>'All enrolled staff'} }}@if($audienceMode!=='selected') ({{ $staffCount }} staff)@endif
