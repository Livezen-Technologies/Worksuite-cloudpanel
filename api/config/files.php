<?php

return [
    // FM-002: largest single upload, in KB (default 2 GB). PHP's upload_max_filesize and
    // post_max_size, and the web server's body limit, must allow at least this much.
    'max_upload_kb' => (int) env('FILES_MAX_UPLOAD_KB', 2 * 1024 * 1024),
    // How long a finished download waits on the panel's disk before it is pruned.
    'download_ttl_hours' => (int) env('FILES_DOWNLOAD_TTL_HOURS', 24),
];
