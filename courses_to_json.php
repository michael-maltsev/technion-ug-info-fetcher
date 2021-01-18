<?php

set_error_handler(
    function($severity, $message, $file, $line) {
        //xdebug_break();
        throw new ErrorException($message, $severity, $severity, $file, $line);
    }
);

require_once 'course_info_fetcher.php';

$options = [];
if ($argc > 1) {
    parse_str($argv[1], $options);
}

$fetched = course_info_fetcher\fetch($options);
if ($fetched === false) {
    echo "Fetching failed\n";
    exit(1);
}

if (count($fetched['info']) == 0) {
    echo "No courses were fetched\n";
    exit(1);
}

echo "Downloaded {$fetched['downloaded']} courses to cache\n";
$has_failed = $fetched['failed'] > 0;
if ($has_failed) {
    echo "WARNING: failed to download {$fetched['failed']} courses\n";
    echo "You might want to run the script again\n";
}

$filename = "courses_{$fetched['semester']}.json";
echo "Writing result to '$filename'\n";
file_put_contents($filename, json_encode($fetched['info'], JSON_UNESCAPED_UNICODE));

echo "Done!\n";
exit($has_failed ? 1 : 0);
