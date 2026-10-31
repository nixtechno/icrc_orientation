# Fullscreen assessments

Staff see **Start Assessment / Quiz** with a bold notice and acknowledgement checkbox before beginning. Assignments and presentations retain their normal submission flow.

The button requests browser fullscreen first. If fullscreen is unavailable or denied, no new attempt is created. Once accepted, the server starts the attempt and the question form is loaded into the existing fullscreen document. This avoids losing fullscreen during a page navigation.

## Submission behaviour

- Leaving fullscreen ends the attempt and submits the answers currently in the form. The ended screen blocks further editing and offers a retry if submission cannot be confirmed.
- Clicking **Submit final answers** follows the normal final submission flow. A subsequent fullscreen-exit event cannot overwrite the submitted result.
- Refreshing, navigating away or closing the page queues a CSRF-protected submission beacon. Saved drafts also remain on the server.
- Reopening the activity or complete page after a fullscreen attempt has begun finalizes the last saved draft if the close notification was lost. It cannot resume that attempt.
- Existing due dates and time limits are enforced. Answers arriving after expiry cannot replace the last valid saved draft. Written questions still require manual grading; scores remain private until released.

Drafts are saved after input and periodically while active. Revision numbers reject stale saves. Row locks and duplicate checks keep final submission idempotent. A per-launch token makes retrying the same start request safe without opening another attempt.

The application records the submission reason for review: manual, fullscreen exit, page exit, deadline, reopened, or loading failure.

## Browser limits

Fullscreen needs a supported browser, a direct user gesture and browser permission. Closing a tab/browser uses best-effort delivery: browsers cannot guarantee a request during force termination, power failure or an offline shutdown. Previously saved answers are retained, and reopening finalizes them. Large closing-page payloads fall back to the saved draft because browser keepalive requests have a size limit. This is a browser-based workflow, not a guarantee of secure proctoring or browser-state attestation.

## Verification

Tests cover consent, fullscreen failure, draft versions, current-answer submission, repeated events, manual submission, page-close beacons, retries, deadlines, browser history, ownership, scoring and result-release privacy. A real Chrome browser check entered fullscreen, selected an answer, exited fullscreen and confirmed that exactly one automatic submission request ended the attempt.
