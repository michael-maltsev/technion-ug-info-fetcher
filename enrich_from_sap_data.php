<?php

// Print errors only once: https://stackoverflow.com/a/9002669
ini_set('log_errors', 'On');
ini_set('display_errors', 'Off');

// Include argument values.
ini_set('zend.exception_ignore_args', 'Off');
ini_set('zend.exception_string_param_max_len', '15');

$longopts  = [
    "semester:",
    "input:",
    "output:",
];
$options = getopt('', $longopts);
if (!isset($options['semester'], $options['input'], $options['output'])) {
    echo "Usage: php enrich_from_sap_data.php --semester <semester> --input <input_file> --output <output_file>\n";
    exit(1);
}

$semester = $options['semester'];
$input = $options['input'];
$output = $options['output'];

$year = substr($semester, 0, 4);
$session = substr($semester, 4);

$sap_url = 'https://michael-maltsev.github.io/technion-sap-info-fetcher/courses_';
$sap_url .= $year;
$sap_url .= '_';
$sap_url .= intval($session) - 1 + 200;
$sap_url .= '.json';

$sap_json_str = file_get_contents($sap_url);
$sap_data = json_decode($sap_json_str, true);

$json_str = file_get_contents($input);
$data = json_decode($json_str, true);

$sap_courses = [];
foreach ($sap_data as $item) {
    $course = $item['general']['מספר מקצוע'];
    $course = to_old_course_number($course);
    $sap_courses[$course] = $item;
}

foreach ($data as &$item) {
    $course = $item['general']['מספר מקצוע'];
    if (isset($sap_courses[$course])) {
        enrich_course($item, $sap_courses[$course]);
    }
}
unset($item);

file_put_contents($output, json_encode($data, JSON_UNESCAPED_UNICODE));

echo "Done!\n";
exit(0);

function enrich_course(&$item, $sap_item) {
    $general = &$item['general'];
    $sap_general = $sap_item['general'];

    foreach (['מועד א', 'מועד ב', 'מועד ג'] as $exam) {
        if (!isset($general[$exam], $sap_general[$exam])) {
            continue;
        }
    
        if (!isset($general[$exam])) {
            echo "Warning: Only SAP has course - $exam\n";
            continue;
        }
    
        if (!isset($sap_general[$exam])) {
            echo "Warning: Only students has course - $exam\n";
            continue;
        }

        $general[$exam] = $sap_general[$exam];
    }
}

function to_old_course_number($course) {
    if (preg_match('/^970300(\d\d)$/', $course, $match)) {
        return '9730' . $match[1];
    }

    if (preg_match('/^097300\d\d$/', $course)) {
        return $course;
    }

    if (preg_match('/^0(\d\d\d)0(\d\d\d)$/', $course, $match)) {
        return $match[1] . $match[2];
    }

    return $course;
}
