# Fullscreen assessments

Staff see **Start Assessment / Quiz** with a bold notice and acknowledgement checkbox before beginning. Assignments and presentations retain their normal submission flow.

The button requests browser fullscreen first. If fullscreen is unavailable or denied, no new attempt is created. Once accepted, the server starts the attempt and the question form is loaded into the existing fullscreen document. This avoids losing fullscreen during a page navigation.

## Submission behaviour

- Questions are shown one at a time. **Next question** moves on and there is no way back: the earlier question leaves the page, and the server keeps the saved answer for every question before the attempt's stored position (`submissions.question_position`), whatever later requests send. Moving on from an unanswered question asks for confirmation first.
- Every answer change is also kept on the device (browser storage, per attempt and person), so a poor connection does not lose work. Saves that fail tell the person to carry on.
- Leaving fullscreen, clicking **Submit final answers** or the timer reaching zero freezes the device copy and sends one final submission. A subsequent fullscreen-exit event cannot overwrite the submitted result.
- If that final submission does not get through, the attempt ends (fullscreen closes) and the assessment page shows **Submission pending**. It retries every 10 seconds, when the connection returns and on **Try again now**, then loads the results once the server confirms. If the page is closed, the pending copy is sent the next time that person opens the assessment on that device.
- Refreshing, navigating away or closing the page queues a CSRF-protected submission beacon. Saved drafts also remain on the server.
- Reopening the activity or complete page after a fullscreen attempt has begun finalizes the last saved draft if the close notification was lost. It cannot resume that attempt. If the device's pending copy arrives afterwards and is newer, it replaces that partial copy (audited as `submission.late_delivery`) — but only for attempts the server closed by itself (reopened or deadline), and never after answers are released or a facilitator has marked the attempt.
- Due dates and time limits are enforced on drafts: a draft after expiry closes the attempt from the last saved draft. The device's frozen final copy is accepted even when the connection delays it past the deadline. Written questions still require manual grading; scores remain private until released.

Drafts are saved after input and periodically while active. Revision numbers reject stale saves. Row locks and duplicate checks keep final submission idempotent. A per-launch token makes retrying the same start request safe without opening another attempt.

The application records the submission reason for review: manual, fullscreen exit, page exit, deadline, reopened, or loading failure.

## Browser limits

Fullscreen needs a supported browser, a direct user gesture and browser permission. Closing a tab/browser uses best-effort delivery: browsers cannot guarantee a request during force termination, power failure or an offline shutdown. Previously saved answers are retained, and reopening finalizes them. Large closing-page payloads fall back to the saved draft because browser keepalive requests have a size limit. This is a browser-based workflow, not a guarantee of secure proctoring or browser-state attestation.

## Verification

Tests cover consent, fullscreen failure, draft versions, current-answer submission, repeated events, manual submission, page-close beacons, retries, deadlines, browser history, ownership, scoring and result-release privacy. A real Chrome browser check entered fullscreen, selected an answer, exited fullscreen and confirmed that exactly one automatic submission request ended the attempt.
