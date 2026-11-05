{{-- One person's printable report card, opened in its own tab from Staff management. It reports recorded figures only: no grades and no signatures. --}}
@php
    $zone = $programme->timezone;
    $percent = fn ($value) => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    $num = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
    $band = fn ($score, $total) => ! $total ? 's-' : (($p = 100 * $score / $total) >= 85 ? 's5' : ($p >= 70 ? 's4' : ($p >= 50 ? 's3' : 's2')));
    $words = ['present' => 'Present', 'late' => 'Late', 'excused' => 'Excused', 'absent' => 'Absent', 'upcoming' => 'Upcoming', 'none' => 'No attendance taken'];
    $short = ['present' => 'P', 'late' => 'L', 'excused' => 'E', 'absent' => 'A', 'upcoming' => '', 'none' => '–'];
    $submitted = $work->whereIn('status', ['marked', 'awaiting'])->count();
    $taken = $quizzes->whereIn('status', ['marked', 'awaiting'])->count();
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Report card · {{ $user->name }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap">
<style>
:root{--desk:#e7ede8;--paper:#fff;--ink:#15231b;--muted:#5d6e64;--line:#dbe4dd;--soft:#f3f7f4;--green:#075332;--green-2:#1d6b45;
  --ok:#1f7a4c;--ok-bg:#dff1e6;--ok2-bg:#e8f4ec;--late:#9a6510;--late-bg:#fbefd7;--exc:#3c5a8a;--exc-bg:#e2eaf7;--abs:#9b3527;--abs-bg:#f8e0dc;--none:#eef2ef;
  --display:"Instrument Serif",Georgia,"Times New Roman",serif;--body:"Manrope",system-ui,-apple-system,"Segoe UI",sans-serif;color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:var(--desk);color:var(--ink);font:14px/1.5 var(--body);padding:24px 16px}
.toolbar{max-width:820px;margin:0 auto 14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:13px;color:var(--muted)}
.toolbar button{font:700 14px var(--body);padding:10px 18px;border:0;border-radius:8px;background:var(--green);color:#fff;cursor:pointer}
.toolbar button:focus-visible{outline:3px solid var(--green-2);outline-offset:2px}
.sheet{max-width:820px;margin:0 auto;background:var(--paper);border-radius:4px;box-shadow:0 1px 2px #0b2a1a14,0 18px 50px #0b2a1a1f;overflow:hidden}
.band{background:linear-gradient(120deg,var(--green),var(--green-2));color:#fff;padding:24px 32px 20px;display:flex;gap:18px;align-items:center;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.band img{width:62px;height:auto;background:#fff;border-radius:10px;padding:6px;flex:none}
.org{font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;opacity:.85}
.band h1{margin:2px 0 0;font:400 33px/1.05 var(--display)}
.band p{margin:4px 0 0;font-size:12.5px;opacity:.9}
.who{display:grid;grid-template-columns:auto 1fr auto;gap:20px;align-items:center;padding:20px 32px;border-bottom:1px solid var(--line)}
.photo{width:76px;height:92px;border-radius:6px;background:var(--soft);border:1px dashed var(--line);display:grid;place-items:center;color:var(--muted);font-size:10px;text-align:center;overflow:hidden}
.photo img{width:100%;height:100%;object-fit:cover}
.name{font:400 28px/1.1 var(--display);margin:0}
.facts{display:flex;flex-wrap:wrap;gap:4px 18px;margin-top:6px;font-size:12.5px;color:var(--muted)}
.facts b{color:var(--ink)}
.period{text-align:right;font-size:12px;color:var(--muted)}
.period b{display:block;color:var(--ink);font-size:13px}
.result{display:grid;grid-template-columns:repeat(3,1fr);border-bottom:1px solid var(--line)}
.result>div{padding:16px 22px;border-left:1px solid var(--line)}
.result>div:first-child{border-left:0;padding-left:32px}
.label{font-size:10.5px;font-weight:800;letter-spacing:1.1px;text-transform:uppercase;color:var(--muted)}
.mid{font:400 38px/1 var(--display);color:var(--green);margin-top:6px;font-variant-numeric:tabular-nums}
.sub{font-size:11.5px;color:var(--muted);margin-top:4px}
.meter{height:5px;border-radius:999px;background:var(--none);margin-top:10px;overflow:hidden;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.meter i{display:block;height:100%;background:var(--green-2)}
.body{padding:22px 32px 6px;display:grid;gap:24px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.two>section{min-width:0}
h2{margin:0 0 10px;font-size:12px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:var(--green);display:flex;justify-content:space-between;align-items:baseline;gap:10px}
h2 span{font-weight:600;letter-spacing:0;text-transform:none;color:var(--muted);font-size:12px}
.weeks{display:grid;gap:6px}
.week{display:grid;grid-template-columns:52px repeat(5,1fr);gap:5px;align-items:center}
.week>b{font-size:11px;color:var(--muted)}
.cell{border-radius:6px;padding:5px 3px 4px;text-align:center;font-variant-numeric:tabular-nums;min-width:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.cell small{display:block;font-size:9.5px;color:var(--muted);font-weight:600}
.cell strong{font-size:12px}
.s5{background:var(--ok-bg);color:var(--ok)}.s4{background:var(--ok2-bg);color:var(--ok)}.s3{background:var(--late-bg);color:var(--late)}.s2{background:var(--abs-bg);color:var(--abs)}.s-{background:var(--none);color:var(--muted)}
.present{background:var(--ok-bg);color:var(--ok)}.late{background:var(--late-bg);color:var(--late)}.excused{background:var(--exc-bg);color:var(--exc)}.absent{background:var(--abs-bg);color:var(--abs)}.upcoming,.none{background:var(--none);color:var(--muted)}
.legend{display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:9px;font-size:11px;color:var(--muted)}
.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:4px;vertical-align:-1px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.tally{display:flex;flex-wrap:wrap;gap:4px 14px;margin-top:6px;font-size:12.5px}
.tally b{font-variant-numeric:tabular-nums}
.tw{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th{text-align:left;font-size:10.5px;letter-spacing:.8px;text-transform:uppercase;color:var(--muted);font-weight:800;padding:0 8px 6px 0;border-bottom:1px solid var(--line)}
td{padding:8px 8px 8px 0;border-bottom:1px solid var(--line);vertical-align:top}
td.n,th.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
td small{display:block;font-size:11px;color:var(--muted)}
.empty{color:var(--muted);font-size:12.5px;margin:0}
.lines{display:grid;gap:24px;padding:12px 0 4px}
.lines i{display:block;border-bottom:1px solid var(--line)}
.foot{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:18px 32px 20px;font-size:11.5px;color:var(--muted);border-top:1px solid var(--line);margin-top:16px}
@media (max-width:720px){.band,.who,.body,.foot{padding-left:18px;padding-right:18px}.who{grid-template-columns:auto 1fr}.period{grid-column:1/-1;text-align:left}
  .result{grid-template-columns:1fr}.result>div,.result>div:first-child{border-left:0;border-top:1px solid var(--line);padding-left:18px}.two{grid-template-columns:1fr}.week{grid-template-columns:40px repeat(5,1fr);gap:3px}}
@page{size:A4;margin:10mm}
@media print{body{background:#fff;padding:0}.toolbar{display:none}.sheet{box-shadow:none;max-width:none;border-radius:0}section,tr{break-inside:avoid}}
</style>
</head>
<body>
<div class="toolbar"><span>Report card for {{ $user->name }}. Check the figures, then print or save as PDF.</span><button type="button" onclick="window.print()">Print</button></div>
<article class="sheet">
  <header class="band">
    <img src="{{ $programme->logoUrl() }}" alt="ICRC logo">
    <div>
      <div class="org">Infrastructure Concession Regulatory Commission</div>
      <h1>Participant Report Card</h1>
      <p>{{ $programme->title }} &middot; Staff Capacity, Onboarding, Development &amp; Excellence Experience</p>
    </div>
  </header>

  <section class="who">
    <div class="photo">@if($user->photo_path)<img src="/people/{{ $user->getRouteKey() }}/photo" alt="">@else Passport<br>photo @endif</div>
    <div>
      <p class="name">{{ $user->name }}</p>
      <div class="facts">@if($user->staff_number)<span>Staff no. <b>{{ $user->staff_number }}</b></span>@endif @if($user->department)<span>Department <b>{{ $user->department }}</b></span>@endif @if($user->designation)<span>Designation <b>{{ $user->designation }}</b></span>@endif @if($user->group_name)<span>Group <b>{{ $user->group_name }}</b></span>@endif</div>
    </div>
    <div class="period">Programme period<b>{{ $programme->starts_on->format('j M') }} – {{ $programme->ends_on->format('j M Y') }}</b>{{ $days->count() }} training days</div>
  </section>

  <section class="result">
    <div>
      <div class="label">Assessment average</div>
      <div class="mid">{{ $percent($assessmentAverage) }}</div>
      <div class="sub">{{ $taken }} of {{ $quizzes->count() }} assessments taken</div>
      <div class="meter"><i style="width:{{ $assessmentAverage ?? 0 }}%"></i></div>
    </div>
    <div>
      <div class="label">Attendance</div>
      <div class="mid">{{ $percent($attendance['percentage']) }}</div>
      <div class="sub">Attended {{ $attendance['numerator'] }} of {{ $attendance['denominator'] }} days so far</div>
      <div class="meter"><i style="width:{{ $attendance['percentage'] ?? 0 }}%"></i></div>
    </div>
    <div>
      <div class="label">Assignments &amp; presentations</div>
      <div class="mid">{{ $submitted }}<span style="font-size:20px;color:var(--muted)"> / {{ $work->count() }}</span></div>
      <div class="sub">Submitted</div>
      <div class="meter"><i style="width:{{ $work->count() ? round(100 * $submitted / $work->count()) : 0 }}%"></i></div>
    </div>
  </section>

  <div class="body">
    <div class="two">
      <section>
        <h2>Daily assessment checks <span>Score by day</span></h2>
        <div class="weeks">@foreach($grid as $week => $cells)<div class="week" style="grid-template-columns:52px repeat({{ max(5, $cells->count()) }},1fr)"><b>Week {{ $week }}</b>@foreach($cells as $cell)
          @if($cell['quiz'] === 'marked')<div class="cell {{ $band($cell['score'], $cell['total']) }}" title="{{ $cell['day']->date->format('D j M') }}"><small>D{{ $cell['day']->number }}</small><strong>{{ $num($cell['score']) }}/{{ $num($cell['total']) }}</strong></div>
          @else<div class="cell s-" title="{{ $cell['day']->date->format('D j M') }}: {{ ['none' => 'No assessment', 'open' => 'Not yet due', 'awaiting' => 'Awaiting marking', 'missed' => 'Not taken'][$cell['quiz']] }}"><small>D{{ $cell['day']->number }}</small><strong>{{ ['none' => '', 'open' => '', 'awaiting' => '…', 'missed' => '✕'][$cell['quiz']] }}</strong></div>@endif
        @endforeach</div>@endforeach</div>
        <div class="legend"><span><i class="s5"></i>85% and above</span><span><i class="s4"></i>70–84%</span><span><i class="s3"></i>50–69%</span><span><i class="s2"></i>Below 50%</span><span>✕ Not taken</span><span>… Awaiting marking</span></div>
      </section>
      <section>
        <h2>Attendance <span>{{ $tally['present'] + $tally['late'] }} days attended</span></h2>
        <div class="weeks">@foreach($grid as $week => $cells)<div class="week" style="grid-template-columns:52px repeat({{ max(5, $cells->count()) }},1fr)"><b>Week {{ $week }}</b>@foreach($cells as $cell)<div class="cell {{ $cell['attendance'] }}" title="{{ $cell['day']->date->format('D j M') }}: {{ $words[$cell['attendance']] }}"><small>D{{ $cell['day']->number }}</small><strong>{{ $short[$cell['attendance']] }}</strong></div>@endforeach</div>@endforeach</div>
        <div class="legend"><span><i class="present"></i>Present</span><span><i class="late"></i>Late</span><span><i class="excused"></i>Excused</span><span><i class="absent"></i>Absent</span><span><i class="upcoming"></i>Upcoming</span></div>
        <div class="tally"><span>Present <b>{{ $tally['present'] }}</b></span><span>Late <b>{{ $tally['late'] }}</b></span><span>Excused <b>{{ $tally['excused'] }}</b></span><span>Absent <b>{{ $tally['absent'] }}</b></span></div>
      </section>
    </div>

    <section>
      <h2>Assessments <span>Cohort average shown for comparison</span></h2>
      @if($quizzes->isEmpty())<p class="empty">No assessments have been assigned yet.</p>@else
      <div class="tw"><table><thead><tr><th>Day</th><th>Assessment</th><th class="n">Score</th><th class="n">Cohort avg</th></tr></thead><tbody>
      @foreach($quizzes as $q)<tr><td>{{ $q['day'] ? 'Day '.$q['day']->number : '—' }}<small>{{ $q['day']?->date->format('j M') }}</small></td><td>{{ $q['activity']->title }}</td>
        <td class="n">@if($q['status'] === 'marked'){{ $num($q['score']) }} / {{ $num($q['total']) }}@else<span style="color:var(--muted)">{{ ['awaiting' => 'Awaiting marking', 'missed' => 'Not taken', 'open' => 'Not yet due'][$q['status']] }}</span>@endif</td>
        <td class="n">{{ $q['cohort'] === null ? '—' : $num($q['cohort']) }}</td></tr>@endforeach
      </tbody></table></div>@endif
    </section>

    <section>
      <h2>Assignments &amp; presentations</h2>
      @if($work->isEmpty())<p class="empty">No assignments or presentations have been assigned yet.</p>@else
      <div class="tw"><table><thead><tr><th>Work</th><th>Facilitator's comment</th><th class="n">Score</th></tr></thead><tbody>
      @foreach($work as $w)<tr><td>{{ $w['activity']->title }}<small>{{ ucfirst($w['activity']->label()) }}@if($w['activity']->due_at) &middot; due {{ $w['activity']->due_at->timezone($zone)->format('j M') }}@endif</small></td>
        <td>{{ $w['submission']?->feedback ?: '—' }}</td>
        <td class="n">@if($w['status'] === 'marked'){{ $num($w['submission']->score) }}@else<span style="color:var(--muted)">{{ ['awaiting' => 'Awaiting marking', 'missed' => 'Not submitted', 'open' => 'Not yet due'][$w['status']] }}</span>@endif</td></tr>@endforeach
      </tbody></table></div>@endif
    </section>

    <section>
      <h2>Comments</h2>
      <div class="lines" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
    </section>
  </div>

  <footer class="foot"><span>Compiled from this participant's attendance, assessment and assignment records in the SCODEX portal.</span><span>Generated {{ $generatedAt->format('j F Y, H:i') }}</span></footer>
</article>
</body>
</html>
