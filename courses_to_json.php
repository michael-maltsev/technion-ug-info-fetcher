<?php

set_error_handler(
    function($severity, $message, $file, $line) {
        //xdebug_break();
        throw new ErrorException($message, $severity, $severity, $file, $line);
    }
);

require_once 'course_info_fetcher.php';

$fetched = course_info_fetcher\fetch();
if ($fetched === false) {
    exit("Fetching failed\n");
}

echo "Downloaded {$fetched['downloaded']} courses to cache\n";
if ($fetched['failed'] > 0) {
    echo "WARNING: failed to download {$fetched['failed']} courses\n";
    echo "You might want to run the script again\n";
}

$filename = "courses_{$fetched['semester']}.json";
echo "Writing result to '$filename'\n";
file_put_contents($filename, json_encode($fetched['info'], JSON_UNESCAPED_UNICODE));

echo "Done!\n";
