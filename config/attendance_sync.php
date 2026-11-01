<?php

return [
    // Set on the venue server only: the live site's address, e.g. https://icrcscodex.bcommz.com
    'url' => env('ATTENDANCE_SYNC_URL'),

    // The same long random secret on the venue server and the live site (at least 32 characters).
    'token' => env('ATTENDANCE_SYNC_TOKEN'),

    // A short name for this copy of the app, stored on every attendance record it writes.
    'origin' => env('ATTENDANCE_SYNC_ORIGIN', env('ATTENDANCE_SYNC_URL') ? 'venue' : 'online'),
];
