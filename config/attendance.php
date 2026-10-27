<?php

return ['rp_id' => env('ATTENDANCE_RP_ID', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)), 'origin' => rtrim(env('ATTENDANCE_ORIGIN', env('APP_URL', 'http://localhost')), '/'), 'challenge_seconds' => 120];
