@extends('layout')
@section('content')
<div class="page-heading"><div><span class="eyebrow">ATTENDANCE SECURITY</span><h1>Verification audit trail</h1><p class="muted">Restricted to attendance facilitators and administrators. Timestamps: Africa/Lagos.</p></div><a href="/attendance">Attendance dashboard</a></div>
<div class="table-wrap"><table><thead><tr><th>Time / staff</th><th>Event / result</th><th>Reason / actor</th><th>Request</th></tr></thead><tbody>@foreach($logs as $log)<tr><td>{{ \Carbon\Carbon::parse($log->created_at)->timezone('Africa/Lagos')->format('d M Y H:i:s') }}<br>{{ $log->name }} · {{ $log->staff_number }}</td><td>{{ $log->event }}<br>{{ $log->verified?'Accepted':'Rejected' }}</td><td>{{ $log->reason }}<br><span class="muted">Actor: {{ $log->actor_name }}</span></td><td>{{ $log->ip_address }}<details><summary>Browser</summary>{{ $log->user_agent }}</details></td></tr>@endforeach</tbody></table></div>{{ $logs->links() }}
@endsection
