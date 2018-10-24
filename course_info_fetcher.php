<?php

namespace course_info_fetcher;

/*
 * Unusual courses so far: 014006, 016208, 394902, 014146
 * Buggy course pages: 014146, 014325, 014506, 014708, 014878, 014880, 014881, 014958, 017001, 064209, 094241, 094334
 * */

function fetch($options = []) {
    $cache_dir = isset($options['cache_dir']) ? $options['cache_dir'] : 'course_info_cache';
    $courses_list_from_rishum = isset($options['courses_list_from_rishum']) ? $options['courses_list_from_rishum'] : null;
    $repfile_cache_life = isset($options['repfile_cache_life']) ? intval($options['repfile_cache_life']) : 60*60*24*365*10;
    $course_cache_life = isset($options['course_cache_life']) ? intval($options['course_cache_life']) : 60*60*24*365*10;
    $simultaneous_downloads = isset($options['simultaneous_downloads']) ? intval($options['simultaneous_downloads']) : 64;
    $download_timeout = isset($options['download_timeout']) ? intval($options['download_timeout']) : 60*10;
    $try_until_all_downloaded = isset($options['try_until_all_downloaded']) ? filter_var($options['try_until_all_downloaded'], FILTER_VALIDATE_BOOLEAN) : false;

    if (!is_dir($cache_dir)) {
        mkdir($cache_dir);
    }

    if (!isset($courses_list_from_rishum)) {
        $repfile_filename = "$cache_dir/REPFILE.zip";
        if (!download_repfile($repfile_filename, $repfile_cache_life)) {
            return false;
        }

        $data = get_courses_from_repfile($repfile_filename);
        if ($data === false) {
            return false;
        }

        list($semester, $courses) = $data;
    } else {
        $semester = $courses_list_from_rishum;

        $courses = get_courses_from_rishum($semester);
        if ($courses === false) {
            return false;
        }
    }

    list($downloaded, $failed) = download_courses(
        $courses, $semester, $cache_dir, $course_cache_life, $simultaneous_downloads, $download_timeout);

    if ($try_until_all_downloaded) {
        while ($failed > 0) {
            //echo "$failed failed\n";
            sleep(10);
            list($downloaded_new, $failed) = download_courses(
                $courses, $semester, $cache_dir, $course_cache_life, $simultaneous_downloads, $download_timeout);

            $downloaded += $downloaded_new;
        }
    }

    //$debug_filename = "$cache_dir/_debug.txt";
    //file_put_contents($debug_filename, '');

    $prev_libxml_use_internal_errors = libxml_use_internal_errors(true);

    $fetched_info = [];
    foreach ($courses as $course) {
        $html = file_get_contents("$cache_dir/$semester/$course.html");
        if ($html == '') {
            continue;
        }

        $html = fix_course_html($html);

        $dom = new \DOMDocument;
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $errors = get_course_errors($xpath);
        if (count($errors) > 0) {
            assert(count($errors) == 1);
            assert(preg_match('#^מקצוע \d{6} לא קיים$#u', $errors[0]));
            continue;
        }

        if (is_course_closed($xpath)) {
            continue;
        }

        $info = get_course_info($dom, $xpath);
        assert(!isset($info['general']['פקולטה']));
        $faculty_name = faculty_name_from_course_number($course);
        $info['general'] = ['פקולטה' => $faculty_name] + $info['general'];
        //file_put_contents($debug_filename, print_r($info, true), FILE_APPEND);
        $fetched_info[] = $info;
    }

    libxml_use_internal_errors($prev_libxml_use_internal_errors);

    return [
        'semester' => $semester,
        'info' => $fetched_info,
        'downloaded' => $downloaded,
        'failed' => $failed
    ];
}

function faculty_name_from_course_number($course) {
    $faculties = [
        '01' => 'הנדסה אזרחית וסביבתית',
        '03' => 'הנדסת מכונות',
        '04' => 'הנדסת חשמל',
        '05' => 'הנדסה כימית',
        '06' => 'הנדסת ביוטכנולוגיה ומזון',
        '08' => 'הנדסת אוירונוטיקה וחלל',
        '09' => 'הנדסת תעשייה וניהול',
        '10' => 'מתמטיקה',
        '11' => 'פיזיקה',
        '12' => 'כימיה',
        '13' => 'ביולוגיה',
        '19' => 'מתמטיקה שימושית',
        '20' => 'ארכיטקטורה ובינוי ערים',
        '21' => 'חינוך למדע וטכנולוגיה',
        '23' => 'מדעי המחשב',
        '27' => 'רפואה',
        '31' => 'מדע והנדסה של חומרים',
        '32' => 'לימודים הומניסטיים ואמנויות',
        '33' => 'הנדסה ביו-רפואית',
        '39' => 'ספורט',
    ];
    $two_digits = substr($course, 0, 2);
    if (isset($faculties[$two_digits])) {
        return $faculties[$two_digits];
    } else {
        return '';
    }
}

function download_repfile($repfile_filename, $repfile_cache_life) {
    if (!is_valid_zip_file($repfile_filename) ||
        time() - filemtime($repfile_filename) > $repfile_cache_life) {
        //echo "Downloading Repfile...\n";
        $result = file_put_contents($repfile_filename,
            fopen('http://ug3.technion.ac.il/rep/REPFILE.zip', 'r'));
        if ($result === false) {
            trigger_error("Cannot download repfile", E_USER_ERROR);
            return false;
        }
        if (!is_valid_zip_file($repfile_filename)) {
            trigger_error("Downloaded repfile is invalid", E_USER_ERROR);
            return false;
        }
    }
    return true;
}

function is_valid_zip_file($filename) {
    $zip = new \ZipArchive;
    return $zip->open($filename, \ZipArchive::CHECKCONS) === TRUE;
}

function get_courses_from_repfile($repfile_filename) {
    $zip = new \ZipArchive;
    $result = $zip->open($repfile_filename);
    if ($result !== true) {
        trigger_error("Cannot open repfile zip file", E_USER_ERROR);
        return false;
    }

    $repfile = $zip->getFromName('REPY');
    $repfile = iconv('IBM862', 'UTF-8', $repfile);

    $courses = [];
    $semester = false;
    $faculties = explode("\r\n\r\n", $repfile);
    $faculties = array_filter(array_map('trim', $faculties));
    foreach ($faculties as $id => $faculty) {
        $p = <<<'EOF'
            #
            \+=+\+\r\n
            \|\s* (.+?) \s* - \s* תועש \s+ תכרעמ \s*\|\r\n
            \|\s* (\S+) \s+ (\S+) \s+ רטסמס \s*\|\r\n
            \+=+\+\r\n
            #ux
EOF;
        if (preg_match($p, $faculty, $matches)) {
            //$faculty_name = utf8_strrev($matches[1]);
            $faculty_semester = heb_semester_to_num($matches[2], $matches[3]);
        } else {
            $p = <<<'EOF'
                #
                \+=+\+\r\n
                \|\s* (\S+) \s+ (\S+) \s+ רטסמס \s* - \s* (טרופס \s+ תועוצקמ) \s*\|\r\n
                \+=+\+\r\n
                #ux
EOF;
            if (preg_match($p, $faculty, $matches)) {
                //$faculty_name = utf8_strrev($matches[3]);
                $faculty_semester = heb_semester_to_num($matches[1], $matches[2]);
            } else {
                assert(0);
                //$faculty_name = strrev("unknown$id");
                $faculty_semester = false;
            }
        }

        assert($faculty_semester !== false);
        if ($semester === false) {
            $semester = $faculty_semester;
        } else {
            assert($semester === $faculty_semester);
        }

        $p = <<<'EOF'
            #
            \+-+\+\r\n
            \|\s* .*? \s* (\d{6}) \s*\|\r\n
            \| .*? \|\r\n
            \+-+\+\r\n
            #ux
EOF;
        preg_match_all($p, $faculty, $matches);

        $courses = array_merge($courses, $matches[1]);
    }

    sort($courses);

    return [$semester, $courses];
}

function heb_semester_to_num($year, $season) {
    // TODO: implement proper conversion
    $year_array = [
        'ז"עשת' => '2016',
        'ח"עשת' => '2017',
        'ט"עשת' => '2018',
        'פ"שת' => '2019',
        'א"פשת' => '2020',
        'ב"פשת' => '2021',
        'ג"פשת' => '2022',
        'ד"פשת' => '2023',
    ];
    $season_array = [
        'ףרוח' => '01',
        'ביבא' => '02',
        'ץיק' => '03',
    ];

    return $year_array[$year] . $season_array[$season];
}

function get_courses_from_rishum($semester) {
    $ch = curl_init('http://ug3.technion.ac.il/rishum/search');

    $result = find_courses_in_rishum_by_name($ch, $semester, '');
    list($success, $data) = $result;
    if ($success) {
        sort($data);
        return $data;
    }

    if ($data != 'too_many') {
        return false;
    }

    // Hebrew letters, least common letters first.
    $heb_letters = ['ץ', 'ך', 'ף', 'ז', 'צ', 'ן', 'ג', 'ם', 'ע', 'ס', 'ח', 'ט', 'ש', 'פ', 'כ', 'ד', 'ק', 'א', 'ל', 'נ', 'ב', 'ה', 'ר', 'ת', 'מ', 'ו', 'י'];

    $courses = [];

    for ($i = 0; $i < count($heb_letters); $i++) {
        $letter = $heb_letters[$i];
        $courses_to_append = get_courses_from_rishum_helper($ch, $semester, $letter, array_slice($heb_letters, $i));
        if ($courses_to_append === false) {
            return false;
        }

        $courses = array_unique(array_merge($courses, $courses_to_append));
    }

    sort($courses);
    return $courses;
}

function get_courses_from_rishum_helper($ch, $semester, $course_name_substring, $dictionary_letters) {
    $result = find_courses_in_rishum_by_name($ch, $semester, $course_name_substring);
    list($success, $data) = $result;
    if ($success) {
        return $data;
    }

    if ($data != 'too_many') {
        return false;
    }

    $courses = [];

    foreach ($dictionary_letters as $letter) {
        $new_substring = $course_name_substring . $letter;
        $courses_to_append = get_courses_from_rishum_helper($ch, $semester, $new_substring, $dictionary_letters);
        if ($courses_to_append === false) {
            return false;
        }

        $courses = array_unique(array_merge($courses, $courses_to_append));
    }

    return $courses;
}

function find_courses_in_rishum_by_name($ch, $semester, $course_name_substring) {
    $post_fields = "CNM=$course_name_substring&CNO=&PNT=&FAC=&LLN=&LFN=&SEM=$semester"
        ."&RECALL=Y&D1=on&D2=on&D3=on&D4=on&D5=on&D6=on&FTM=&TTM=&SIL="
        ."&OPTCAT=on&OPTSEM=on&OPTSTUD=on&doSearch=Y&Search=+++%D7%97%D7%A4%D7%A9+++";

    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);

    $html = curl_exec($ch);
    if ($html === false)
        return [false, 'curl'];

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code != 200)
        return [false, 'http'];

    if (!is_valid_rishum_html($html))
        return [false, 'html'];

    $prev_libxml_use_internal_errors = libxml_use_internal_errors(true);

    $dom = new \DOMDocument;
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new \DOMXPath($dom);

    libxml_use_internal_errors($prev_libxml_use_internal_errors);

    $errors = get_course_errors($xpath);
    if (count($errors) > 0) {
        assert(count($errors) == 1);
        if ($errors[0] == 'לא נמצאו מקצועות מתאימים') {
            return [true, []];
        }

        assert($errors[0] == 'כמות המידע העונה לתנאי החיפוש עברה את המקסימום המותריש לצמצם את טווח החיפוש');
        return [false, 'too_many'];
    }

    $courses = $xpath->query(
        "//section[@class='search-results']/div[@class='result-row']/div[@class='course-number']/a");
    $courses = iterator_to_array($courses);
    $courses = array_map(function ($node) {
        return trim($node->nodeValue);
    }, $courses);

    return [true, $courses];
}

function download_courses($courses, $semester, $cache_dir, $course_cache_life, $simultaneous_downloads, $download_timeout) {
    if (!is_dir("$cache_dir/$semester")) {
        mkdir("$cache_dir/$semester");
    }
    $requests = array_map(function ($course) use ($semester, $cache_dir) {
        $suffix = $semester != '' ? "/$semester" : '';
        return [
            'url' => "http://ug3.technion.ac.il/rishum/course/$course$suffix",
            'filename' => "$cache_dir/$semester/$course.html"
        ];
    }, $courses);

    $min_valid_cache_time = time() - $course_cache_life;
    $should_request_course = function ($request) use ($min_valid_cache_time) {
        return
            !is_file($request['filename']) ||
            filesize($request['filename']) == 0 ||
            filemtime($request['filename']) < $min_valid_cache_time;
    };

    $requests = array_filter($requests, $should_request_course);
    $requested_count = count($requests);

    //echo count($requests)." requests, downloading...\n";
    foreach (array_chunk($requests, $simultaneous_downloads) as $chunk) {
        multi_request($chunk, [CURLOPT_FAILONERROR => true, CURLOPT_TIMEOUT => $download_timeout]);
    }

    $requests = array_filter($requests, function ($request) use ($should_request_course) {
        if ($should_request_course($request)) {
            return true;
        }

        $html = file_get_contents($request['filename']);
        if (!is_valid_rishum_html($html)) {
            file_put_contents($request['filename'], '');
            return true;
        }

        return false;
    });

    $failed_count = count($requests);

    //echo "Done, ".count($requests)." failed\n";
    return [$requested_count - $failed_count, $failed_count];
}

function is_valid_rishum_html($html) {
    // Verifies that the html response is not truncated. Helps detect partial server responses.
    $html_rtrimmed = rtrim($html);
    if (substr($html_rtrimmed, -strlen('</html>')) !== '</html>') {
        // If a search result has only one result, the page ends with a JS redirect.
        $p = "#<script>location\.href='[^']*?'</script>$#";
        if (!preg_match($p, $html_rtrimmed)) {
            return false;
        }
    }

    if (strpos($html, 'Warning: mysqli') !== false) {
        return false;
    }

    return true;
}

function fix_course_html($html) {
    $p = '#(<a href=\'http://techmvs\.technion\.ac\.il/cics/wmn/wmrns1x\?'
        .'PSEM=\d+&amp;PSUB=\d+&amp;PGRP=\d+&amp;PLAST=\d+\'>)\1(.*?</a>)\2#u';
    return preg_replace($p, '$1$2', $html);
}

function is_course_closed(\DOMXPath $xpath) {
    $properties = $xpath->query("//div[@class='properties-close']");
    assert($properties->length == 1);
    $properties = iterator_to_array($properties);
    $properties = array_map(function ($node) {
        return trim($node->nodeValue);
    }, $properties);

    return $properties[0] == 'המקצוע לא נלמד בסמסטר זה';
}

function get_course_errors(\DOMXPath $xpath) {
    $errors = $xpath->query("//div[@class='error-msg']");
    $errors = iterator_to_array($errors);
    $errors = array_map(function ($node) {
        return trim($node->nodeValue);
    }, $errors);

    return $errors;
}

function get_course_info(\DOMDocument $dom, \DOMXPath $xpath) {
    $info = [
        'general' => get_course_general_info($dom, $xpath),
        'schedule' => get_course_schedule($dom, $xpath)
    ];
    return $info;
}

function get_course_general_info(\DOMDocument $dom, \DOMXPath $xpath) {
    $properties = $xpath->query("//div[@class='property']");
    $properties = iterator_to_array($properties);
    $properties = array_map(function ($node) {
        return trim($node->nodeValue);
    }, $properties);

    $property_values= $xpath->query("//div[@class='property-value']");
    $property_values = iterator_to_array($property_values);
    $property_values = array_map(function ($node) use ($dom) {
        $xml = $dom->saveXML($node);
        $stripped = strip_tags($xml, '<br><hr>');
        $stripped = preg_replace('#(<br[^>]*>\s*)?<hr.*?>#u', "\n====================\n", $stripped);
        $stripped = preg_replace('#<br.*?>#u', "\n", $stripped);
        $stripped = preg_replace('#^[^\S\n]+|[^\S\n]+$#um', '', $stripped);
        return trim($stripped);
    }, $property_values);

    $info = array_combine($properties, $property_values);
    return $info;
}

function get_course_schedule(\DOMDocument $dom, \DOMXPath $xpath) {
    $properties = $xpath->query(
        "//table[@class='rishum-groups']//tr[1]/td[not(position()=2)]");
    $properties = iterator_to_array($properties);
    $properties = array_map(function ($node) {
        return trim($node->nodeValue);
    }, $properties);

    $prop_count = count($properties);
    $staff_sentinel = '{@SIS@}';

    $property_values = $xpath->query(
        "//table[@class='rishum-groups']//tr[position()>1]/td[not(position()=2)]");
    $property_values = iterator_to_array($property_values);
    assert((count($property_values) % $prop_count) == 0);
    $property_values = array_map(function ($node) use ($dom, $staff_sentinel) {
        $xml = $dom->saveXML($node);
        $stripped = strip_tags($xml, '<a><br>');
        $p = '#<a href="http://techmvs\.technion\.ac\.il/cics/wmn/wmrns1x\?'
            .'PSEM=(\d+)&amp;PSUB=(\d+)&amp;PGRP=(\d+)&amp;PLAST=\d+">#u';
        $stripped = preg_replace($p,
            '$0$3'.$staff_sentinel, $stripped, -1, $count);
        assert($count == substr_count($stripped, '<a'));
        $stripped = strip_tags($stripped, '<br>');
        $stripped = preg_replace('#<.*?>#u', "\n", $stripped);
        $stripped = preg_replace('#^[^\S\n]+|[^\S\n]+$#um', '', $stripped);
        return rtrim($stripped);
    }, $property_values);

    $info_rows = array_chunk($property_values, $prop_count);
    $info = [];
    foreach ($info_rows as $row) {
        assert(!empty($row[0]));
        if (count(array_filter(array_slice($row, 1))) == 0) {
            // Only the registration group, skip...
            continue;
        }

        $groups = explode("\n", $row[1]);
        foreach ($groups as $group) {
            if ($group === '') {
                /* // Verify that it's a known buggy course page.
                preg_match('#<div class="property-value">\s*(\d{6})\b#u', $html, $matches);
                assert(in_array($matches[1], ['014146', '014325', '014506', '014708', '014878', '014880', '014881', '014958', '017001', '064209', '094241', '094334'], true));
                //*/

                foreach ($row as $k => $v) {
                    if ($v !== '') {
                        $ex = explode("\n", $v, 2);
                        if ($ex[0] === '') {
                            $row[$k] = isset($ex[1]) ? $ex[1] : '';
                        }
                    }
                }
                continue;
            }

            $group_row = [];
            foreach ($row as $k => $v) {
                if ($k == 0) {
                    // This is the registration group id,
                    // a single number for the whole row.
                    assert(strpos($v, "\n") === false);
                    $group_row[$properties[$k]] = $v;
                    continue;
                }

                if ($v === '') {
                    $group_row[$properties[$k]] = '';
                    continue;
                }

                if (strpos($v, $staff_sentinel) === false) {
                    $ex = explode("\n", $v, 2);
                    $group_row[$properties[$k]] = $ex[0];
                    $row[$k] = isset($ex[1]) ? $ex[1] : '';
                    continue;
                }

                $ex = explode("\n", $v);

                /* // Duplicate staff check.
                $dups = array_filter(array_count_values($ex), function ($item) { return $item > 1; });
                if (count($dups) > 1 && count(array_unique($dups)) > 1) {
                    echo "======================\n";
                    preg_match('#<div class="property-value">\s*(\d{6})\b#u', $html, $matches);
                    echo $matches[1]."\n";
                    print_r($dups);
                    echo "\n";
                }
                //*/

                $ex = array_filter($ex, function ($sm) use ($groups, $group, $staff_sentinel) {
                    $sm = explode($staff_sentinel, $sm);
                    assert(count($sm) == 2);
                    assert(in_array($sm[0], $groups));
                    return $sm[0] == $group;
                });
                $ex = array_map(function ($sm) use ($staff_sentinel) {
                    return explode($staff_sentinel, $sm, 2)[1];
                }, $ex);
                $ex = array_unique($ex);

                $group_row[$properties[$k]] = str_replace(
                    $staff_sentinel, ';', implode("\n", $ex));
            }

            $info[] = $group_row;
        }

        // Verify that all of the information was consumed.
        $row = array_map(function ($v) use ($staff_sentinel) {
            if (strpos($v, $staff_sentinel) !== false) {
                return '';
            }
            return $v;
        }, $row);
        assert(count(array_filter(array_slice($row, 1))) == 0);
    }

    return $info;
}

// https://stackoverflow.com/a/17496494
function utf8_strrev($str){
    preg_match_all('/./us', $str, $ar);
    return join('', array_reverse($ar[0]));
}

// https://www.phpied.com/simultaneuos-http-requests-in-php-with-curl/
// http://php.net/manual/en/function.curl-multi-select.php#115381
function multi_request($data, $options = array()) {
    // array of curl handles
    $curly = array();
    // data to be returned
    $result = array();

    // multi handle
    $mh = curl_multi_init();

    // loop through $data and create curl handles
    // then add them to the multi-handle
    foreach ($data as $id => $d) {
        $curly[$id] = curl_init();

        $url = (is_array($d) && !empty($d['url'])) ? $d['url'] : $d;
        curl_setopt($curly[$id], CURLOPT_URL, $url);
        curl_setopt($curly[$id], CURLOPT_HEADER, false);
        curl_setopt($curly[$id], CURLOPT_RETURNTRANSFER, true);

        // post? filename?
        if (is_array($d)) {
            if (!empty($d['post'])) {
                curl_setopt($curly[$id], CURLOPT_POST, true);
                curl_setopt($curly[$id], CURLOPT_POSTFIELDS, $d['post']);
            }
            if (!empty($d['filename'])) {
                curl_setopt($curly[$id], CURLOPT_RETURNTRANSFER, false);
                curl_setopt($curly[$id], CURLOPT_FILE, fopen($d['filename'], 'w'));
            }
        }

        // extra options?
        if (!empty($options)) {
            curl_setopt_array($curly[$id], $options);
        }

        curl_multi_add_handle($mh, $curly[$id]);
    }

    // while we're still active, execute curl
    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);

    while ($active && $mrc == CURLM_OK) {
        // wait for activity on any curl-connection
        if (curl_multi_select($mh) == -1) {
            usleep(1);
        }

        // continue to exec until curl is ready to
        // give us more data
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    }

    // get content and remove handles
    foreach ($curly as $id => $c) {
        if (!is_array($data[$id]) || empty($data[$id]['filename'])) {
            $result[$id] = curl_multi_getcontent($c);
        }
        curl_multi_remove_handle($mh, $c);
    }

    // all done
    curl_multi_close($mh);

    return $result;
}
