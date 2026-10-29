<?php

namespace App\Http\Controllers;

use App\Models\ProgrammeSession;
use App\Models\SessionAnswer;
use App\Models\SessionQuestion;
use App\Models\User;
use App\Notifications\ProgrammeUpdate;
use App\Services\Access;
use Illuminate\Http\Request;

class SessionQaController extends Controller
{
    private function scope(ProgrammeSession $s): void
    {
        abort_unless($s->day->week->programme_id === Access::programme()->id, 404);
        abort_unless(auth()->user()->canManage('qa') || ($s->status === 'published' && in_array($s->type, ['session', 'recap', 'presentation'], true)), 403);
    }

    public function index(Request $r)
    {
        $q = ProgrammeSession::whereHas('day.week', fn ($q) => $q->where('programme_id', Access::programme()->id))->whereIn('type', ['session', 'recap', 'presentation'])->with('day');
        if (! $r->user()->canManage('qa')) {
            $q->where('status', 'published');
        }if ($r->filled('q')) {
            $q->where('title', 'like', '%'.$r->q.'%');
        }$sessions = $q->orderBy('programme_day_id')->orderBy('id')->get();

        return view('qa.index', compact('sessions'));
    }

    public function session(ProgrammeSession $session)
    {
        $this->scope($session);
        $moderator = auth()->user()->canManage('qa');
        $canAnswer = auth()->user()->canAnswerSession($session);
        $q = SessionQuestion::with('user', 'answers.user')->where('session_id', $session->id);
        if (! $moderator) {
            $q->where('hidden', false)->where(fn ($q) => $q->where('published', true)->orWhere('user_id', auth()->id()));
        }$questions = $q->latest()->get();

        return view('qa.session', compact('session', 'moderator', 'canAnswer', 'questions'));
    }

    public function ask(Request $r, ProgrammeSession $session)
    {
        $this->scope($session);
        abort_unless($r->user()->role === 'staff', 403);
        $v = $r->validate(['body' => 'required|string|max:5000']);
        $question = SessionQuestion::create(['session_id' => $session->id, 'user_id' => auth()->id(), 'body' => $v['body']]);
        Access::audit('qa.question.submit', (string) $question->id);
        $recipients = User::where('active', true)->whereHas('programmes', fn ($q) => $q->where('programmes.id', Access::programme()->id))->get()->filter(fn ($u) => $u->canManage('qa'));
        foreach ($recipients as $u) {
            $u->notify(new ProgrammeUpdate('Session question awaiting moderation', 'A participant submitted a question for review.', url('/qa/sessions/'.$session->getRouteKey())));
        }

return back()->with('success', 'Question submitted for moderation.');
    }

    public function answer(Request $r, SessionQuestion $question)
    {
        $this->scope($question->session);
        abort_unless($r->user()->canAnswerSession($question->session), 403);
        abort_unless($r->user()->canManage('qa') || ($question->published && ! $question->hidden), 403);
        $v = $r->validate(['body' => 'required|string|max:10000']);
        $published = $r->user()->canManage('qa') && $r->boolean('published');
        $answer = SessionAnswer::create(['session_question_id' => $question->id, 'user_id' => auth()->id(), 'body' => $v['body'], 'published' => $published]);
        Access::audit('qa.answer.create', (string) $answer->id);
        if ($published && $question->published && ! $question->hidden) {
            $this->notify($question);
        }

return back()->with('success', $published ? 'Answer published.' : 'Answer saved for moderator review.');
    }

    public function moderate(Request $r, string $type, string $id)
    {
        Access::manage('qa');
        abort_unless(in_array($type, ['question', 'answer']), 404);
        $item = $type === 'question' ? (new SessionQuestion)->resolveRouteBinding($id) : (new SessionAnswer)->resolveRouteBinding($id);
        abort_unless($item, 404);
        $question = $type === 'question' ? $item : $item->question;
        $this->scope($question->session);
        $v = $r->validate(['action' => 'required|in:publish,unpublish,hide,unhide', 'reason' => 'required|string|max:2000']);
        $before = $item->toArray();
        $item->update(match ($v['action']) {
            'publish' => ['published' => true, 'hidden' => false],'unpublish' => ['published' => false],'hide' => ['hidden' => true],'unhide' => ['hidden' => false]
        });
        Access::audit('qa.moderate', $type.':'.$id, $before, ['record' => $item->toArray(), 'reason' => $v['reason']]);
        if ($type === 'answer' && $v['action'] === 'publish' && $question->published && ! $question->hidden) {
            $this->notify($question);
        }

return back()->with('success', 'Moderation recorded.');
    }

    private function notify(SessionQuestion $q): void
    {
        $q->user->notify(new ProgrammeUpdate('Your session question has an answer','Open the session Q&A to read the published response.',url('/qa/sessions/'.$q->session->getRouteKey())));
    }
}
