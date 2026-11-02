{{-- Upload questions from the Excel template; checked questions wait here until the manager adds them. --}}
@php($pending=session('question_import.'.$activity->getKey()))
<details class="question-import" id="question-import" @if($pending||$errors->has('file')) open @endif><summary>Upload questions from Excel</summary>
<ol class="question-import-steps"><li><a href="/assessments/question-template">Download the Excel template</a>. The second sheet explains each column and has examples.</li><li>Copy the questions into the Questions sheet, one per row. Correct answers can be a letter (B, or A, C for multiple answers) or the exact option text.</li><li>Upload the file below. It is checked first; nothing is added until you confirm.</li></ol>
@if($errors->has('file'))<div class="errors" role="alert"><strong>Fix these rows and upload the file again. No questions were added.</strong><ul>@foreach($errors->get('file') as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
@if($pending)
<div class="question-import-preview"><h3>{{ count($pending) }} {{ count($pending)===1?'question':'questions' }} ready to add</h3>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Type</th><th>Question</th><th>Options</th><th>Correct answer</th><th>Points</th></tr></thead><tbody>
@foreach($pending as $q)<tr><td>{{ $loop->iteration }}</td><td>{{ \App\Services\QuestionImport::TYPES[$q['type']] }}</td><td class="preline">{{ $q['prompt'] }}@if(filled($q['explanation']??null)&&\App\Models\Question::hasExplanations())<div class="muted"><strong>Explanation:</strong> {{ $q['explanation'] }}</div>@endif</td><td>@if($q['options'])<ol type="A" class="question-import-options">@foreach($q['options'] as $option)<li>{{ $option }}</li>@endforeach</ol>@else<span class="muted">Written answer</span>@endif</td><td>{{ $q['type']==='multiple_answers'?implode(', ',json_decode($q['correct_answer'],true)):($q['correct_answer']??'Marked by a facilitator') }}</td><td>{{ $q['points'] }}</td></tr>@endforeach
</tbody></table></div>
<div class="buttons"><form method="post" action="/activities/{{ $activity->getRouteKey() }}/questions/import/confirm">@csrf<button>Add these {{ count($pending) }} {{ count($pending)===1?'question':'questions' }}</button></form><form method="post" action="/activities/{{ $activity->getRouteKey() }}/questions/import/cancel">@csrf<button class="secondary">Cancel</button></form></div></div>
@else
<form method="post" action="/activities/{{ $activity->getRouteKey() }}/questions/import" enctype="multipart/form-data">@csrf<label>Filled-in template (.xlsx)<input type="file" name="file" accept=".xlsx" required></label><button>Check file</button></form>
@endif
</details>
