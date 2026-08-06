<?php
$workflow = file_get_contents( dirname(__DIR__) . '/.github/workflows/integration-ci.yml' );
if ( ! preg_match('/\bpull_request\s*:\s*(?:#.*)?\R(?<body>(?:[ \t]+.*\R?)*)/m',$workflow,$match) ) {
    fwrite(STDERR,"FAIL: integration workflow has no pull_request trigger.\n");
    exit(1);
}
if ( preg_match('/^[ \t]+paths(?:-ignore)?\s*:/m',$match['body']) ) {
    fwrite(STDERR,"FAIL: required integration checks can be skipped by pull-request path filtering.\n");
    exit(1);
}
echo "OK: pull requests, including documentation-only changes, always start required integration checks.\n";
