<?php

// Adds a test staff account and a ten-question test assessment to the isolated demo database, for trying the
// one-question-at-a-time flow and pending submissions on a poor connection. Rerunning it clears the test account's
// attempt, so the assessment can be taken again. It never touches the main database.
putenv('APP_ENV=demo');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'demo';
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{Activity, Programme, Submission, User};
use Illuminate\Support\Facades\{DB, Hash};

if (config('database.connections.mysql.database') !== 'icrc_orientation_demo') {
    fwrite(STDERR, "Refusing to run: this script only writes to the icrc_orientation_demo database. Run php scripts/setup-demo.php first.\n");
    exit(1);
}

DB::transaction(function () {
    $programme = Programme::firstOrFail();
    $tester = User::firstOrNew(['email' => 'tester@icrc-demo.test']);
    $tester->forceFill(['name' => 'Assessment Tester', 'role' => 'staff', 'active' => true, 'permissions' => [], 'password' => Hash::make('IcrcDemo!2026'), 'department' => 'Programme Office', 'designation' => 'Programme Officer', 'staff_number' => 'DEMO-TESTER', 'phone' => '+2340000000000', 'profile' => ['first_name' => 'Assessment', 'surname' => 'Tester', 'programme_information' => 'Synthetic demo profile.'], 'email_verified_at' => now(), 'privacy_acknowledged_at' => now()])->save();
    if (! $tester->programmes()->whereKey($programme->id)->exists()) $tester->programmes()->attach($programme->id);

    $quiz = Activity::firstOrNew(['programme_id' => $programme->id, 'title' => 'TEST — One question at a time']);
    $quiz->forceFill(['kind' => 'quiz', 'instructions' => 'Test assessment. Answer each question and move on with Next question; earlier questions cannot be reopened. Try switching off your connection before submitting to see the pending submission.', 'available_at' => now()->subDay(), 'due_at' => now()->addDays(30), 'attempts' => 1, 'time_limit' => 15, 'published' => true])->save();
    if (! $quiz->questions()->exists()) {
        $questions = [
            ['multiple_choice', 'What does PPP stand for?', ['Public-Private Partnership', 'Public Procurement Policy', 'Private Project Planning'], 'Public-Private Partnership'],
            ['true_false', 'A PPP should allocate risk to the party best able to manage it.', ['True', 'False'], 'True'],
            ['multiple_choice', 'What should a PPP deliver?', ['Public value', 'Guaranteed private profits only', 'No public oversight'], 'Public value'],
            ['multiple_answers', 'Which of these are common PPP risks? Select all that apply.', ['Construction risk', 'Demand risk', 'Weather on the signing day'], ['Construction risk', 'Demand risk']],
            ['true_false', 'The public sector keeps oversight of a PPP after the contract is signed.', ['True', 'False'], 'True'],
            ['multiple_choice', 'Which document sets out the obligations of each party?', ['The PPP contract', 'A press release', 'The annual budget speech'], 'The PPP contract'],
            ['multiple_choice', 'Value for money compares a PPP with…', ['Traditional public procurement', 'Private profit targets', 'Last year\'s weather'], 'Traditional public procurement'],
            ['true_false', 'Every infrastructure project should be delivered as a PPP.', ['True', 'False'], 'False'],
            ['short_answer', 'In one sentence, what is a PPP?', null, null],
            ['multiple_choice', 'Who usually finances construction in a PPP?', ['The private partner', 'Only the end users', 'Nobody'], 'The private partner'],
        ];
        foreach ($questions as [$type, $prompt, $options, $answer]) {
            $quiz->questions()->create(['type' => $type, 'prompt' => $prompt, 'options' => $options, 'correct_answer' => is_array($answer) ? json_encode($answer) : $answer, 'points' => 5]);
        }
    }
    $quiz->participants()->syncWithoutDetaching([$tester->id]);
    $cleared = Submission::where('activity_id', $quiz->id)->where('user_id', $tester->id)->delete();

    echo "Test assessment ready: \"{$quiz->title}\" (10 questions, 15 minute limit).\n";
    echo $cleared ? "Cleared the tester's previous attempt.\n" : '';
    echo "Sign in at http://127.0.0.1:8001/login as tester@icrc-demo.test with IcrcDemo!2026\n";
    echo 'Then open http://127.0.0.1:8001/activities/'.$quiz->getRouteKey()."\n";
});
