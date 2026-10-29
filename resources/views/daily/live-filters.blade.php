<form class="filters attendance-table-filters" data-live-filter>
<label>Date<input type="date" name="date" value="{{ request('date',now('Africa/Lagos')->toDateString()) }}"></label>
<label>Search staff<input type="search" name="name" value="{{ request('name') }}" placeholder="Name, staff number or department"></label>
<label>Department<input name="department" value="{{ request('department') }}" placeholder="All departments"></label>
<label>Status<select name="status"><option value="">All statuses</option>@foreach(['present'=>'Present','late'=>'Late','pending'=>'Pending','absent'=>'Absent','excused'=>'Excused','in'=>'Currently signed in','out'=>'Signed out'] as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Rows per page<select name="per_page">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected(request('per_page',10)==$size)>{{ $size }}</option>@endforeach</select></label>
<input type="hidden" name="sort" value="{{ request('sort','name') }}"><input type="hidden" name="direction" value="{{ request('direction','asc') }}">
<button class="secondary">Apply filters</button>
</form>
