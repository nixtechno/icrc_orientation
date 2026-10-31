# Badge scanner attendance

Open **Daily attendance → Scan with scanner**, then choose **Sign in** or **Sign out**. Each station has one visible input. Use a USB/Bluetooth QR scanner in keyboard mode with an Enter suffix; the input accepts the verification URL already printed in the staff badge QR. The badge slug is also accepted. Scanned URLs are parsed locally and never fetched.

The operator must be an active, enrolled attendance manager (Admin, or Facilitator granted Attendance permission). Keep the station signed in with that account. Staff cannot open or submit to these pages. Only active staff enrolled in the current programme with a current programme staff badge are accepted; admin and facilitator badges are rejected.

Attendance uses today's Africa/Lagos date, current programme dates, required/non-cancelled day and configured windows, including the late threshold. Configure these under Daily attendance before operating a station. Sign-out requires a prior sign-in. Repeated scans do not overwrite timestamps or create extra records. Existing device sign-in can be paired with scanner sign-out, and vice versa.

Successful scans update the same records used by the dashboard, summaries and reports. Each direction records its method and scanning operator; security audit records identify the operator and staff member. A scanner verifies possession of the badge through a manager-operated station; it does not assert device or GPS verification. Existing verification flags are preserved and scanner entries are labelled separately. Reports include sign-in and sign-out methods.

The page sends each scan without a reload, displays confirmation or the rejection reason, then clears/refocuses the field. While a request is pending, additional submissions are blocked. If the connection fails, reconnect and scan again; server duplicate checks protect the original record.
