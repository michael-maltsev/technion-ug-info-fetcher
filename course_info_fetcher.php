<?php

namespace course_info_fetcher;

function fetch($options = []) {
    global $course_info_fetcher_verbose;

    $cache_dir = $options['cache_dir'] ?? 'course_info_cache';
    $semester = $options['semester'] ?? null;
    $repfile_cache_life = intval($options['repfile_cache_life'] ?? 60*60*24*365*10);
    $course_cache_life = intval($options['course_cache_life'] ?? 60*60*24*365*10);
    $simultaneous_downloads = intval($options['simultaneous_downloads'] ?? 64);
    $download_timeout = intval($options['download_timeout'] ?? 60*10);
    $course_info_fetcher_verbose = isset($options['verbose']);

    if (!is_dir($cache_dir)) {
        mkdir($cache_dir);
    }

    log_verbose("Downloading list of courses...\n");

    if (!isset($semester)) {
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
        $courses = get_courses_from_rishum($semester);
        if ($courses === false) {
            return false;
        }
    }

    log_verbose("Downloading course data...\n");

    list($downloaded, $failed) = download_courses(
        $courses, $semester, $cache_dir, $course_cache_life, $simultaneous_downloads, $download_timeout);

    while ($failed > 0) {
        log_verbose("Re-trying download for $failed failed courses...\n");
        sleep(10);
        list($downloaded_new, $failed) = download_courses(
            $courses, $semester, $cache_dir, $course_cache_life, $simultaneous_downloads, $download_timeout);

        $downloaded += $downloaded_new;
    }

    log_verbose("Parsing downloaded data...\n");

    //$debug_filename = "$cache_dir/_debug.txt";
    //file_put_contents($debug_filename, '');

    $prev_libxml_use_internal_errors = libxml_use_internal_errors(true);

    $fetched_info = [];
    foreach ($courses as $course) {
        $html = file_get_contents("$cache_dir/$semester/$course.html");

        // Replace invalid UTF-8 characters.
        // https://stackoverflow.com/a/8215387
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');

        $dom = new \DOMDocument;
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        if (!is_course_active_in_semester($xpath, $semester)) {
            continue;
        }

        $info = get_course_info($dom, $xpath, $semester);

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

function download_repfile($repfile_filename, $repfile_cache_life) {
    if (!is_valid_zip_file($repfile_filename) ||
        time() - filemtime($repfile_filename) > $repfile_cache_life) {
        log_verbose("Downloading Repfile...\n");
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
        'ף"שת' => '2019',
        'א"פשת' => '2020',
        'ב"פשת' => '2021',
        'ג"פשת' => '2022',
        'ד"פשת' => '2023',
        'ה"פשת' => '2024',
        'ו"פשת' => '2025',
        'ז"פשת' => '2026',
        'ח"פשת' => '2027',
        'ט"פשת' => '2028',
    ];
    $season_array = [
        'ףרוח' => '01',
        'ביבא' => '02',
        'ץיק' => '03',
    ];

    return $year_array[$year] . $season_array[$season];
}

function get_courses_from_rishum($semester) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $session_params = get_courses_session_params($ch);
    if ($session_params === false) {
        return false;
    }

    $session_cookie = $session_params['session_cookie'];
    $sesskey = $session_params['sesskey'];

    log_verbose("Getting list of courses from Rishum:\n");
    log_verbose("Page 1...\n");

    $result = get_courses_first_page($ch, $session_cookie, $sesskey, $semester);
    list($success, $data) = $result;
    if (!$success) {
        return false;
    }

    $courses = $data;

    for ($page = 1; ; $page++) {
        log_verbose("Page " . ($page + 1) . "...\n");

        $result = get_courses_next_page($ch, $session_cookie, $page);
        list($success, $data) = $result;
        if (!$success) {
            return false;
        }

        if (count($data) == 0) {
            break;
        }

        $courses = array_unique(array_merge($courses, $data));
    }

    log_verbose("Got list of " . count($courses) . " courses\n");

    sort($courses);
    return $courses;
}

function get_courses_session_params($ch) {
    $url = 'https://students.technion.ac.il/local/technionsearch/search';

    curl_setopt($ch, CURLOPT_URL, $url);

    // https://stackoverflow.com/a/25098798
    $cookies = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header_line) use (&$cookies) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]+)/i', $header_line, $matches)) {
            $cookies[$matches[1]] = $matches[2];
        }
        return strlen($header_line); // needed by curl
    });

    $html = curl_exec($ch);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header_line) {
        return strlen($header_line);
    });
    if ($html === false) {
        return false;
    }

    if (!isset($cookies['MoodleSessionstudentsprod'])) {
        return false;
    }

    $session_cookie = $cookies['MoodleSessionstudentsprod'];

    $p = '#\nM\.cfg = {"wwwroot":"https:\\\\/\\\\/students\.technion\.ac\.il","sesskey":"([0-9a-zA-Z]+)"#u';
    if (!preg_match($p, $html, $matches)) {
        return false;
    }

    $sesskey = $matches[1];

    return [
        'session_cookie' => $session_cookie,
        'sesskey' => $sesskey,
    ];
}

function get_courses_first_page($ch, $session_cookie, $sesskey, $semester) {
    $url = 'https://students.technion.ac.il/local/technionsearch/results';

    $post_fields = 'sesskey=' . $sesskey
        . '&_qf__local_technionsearch_form_search=1'
        . '&course_name='
        . '&academic_framework=_qf__force_multiselect_submission'
        . '&academic_framework%5B%5D=1'
        . '&academic_framework%5B%5D=2'
        . '&min_points=0.0'
        . '&max_points=99.0'
        . '&faculties=_qf__force_multiselect_submission'
        . '&faculties%5B%5D=510'
        . '&faculties%5B%5D=20'
        . '&faculties%5B%5D=13'
        . '&faculties%5B%5D=1'
        . '&faculties%5B%5D=33'
        . '&faculties%5B%5D=520'
        . '&faculties%5B%5D=5'
        . '&faculties%5B%5D=8'
        . '&faculties%5B%5D=6'
        . '&faculties%5B%5D=4'
        . '&faculties%5B%5D=3'
        . '&faculties%5B%5D=85'
        . '&faculties%5B%5D=9'
        . '&faculties%5B%5D=21'
        . '&faculties%5B%5D=12'
        . '&faculties%5B%5D=32'
        . '&faculties%5B%5D=31'
        . '&faculties%5B%5D=610'
        . '&faculties%5B%5D=23'
        . '&faculties%5B%5D=970'
        . '&faculties%5B%5D=511'
        . '&faculties%5B%5D=10'
        . '&faculties%5B%5D=64'
        . '&faculties%5B%5D=11'
        . '&faculties%5B%5D=27'
        . '&lecturer_name='
        . '&daycheckboxgroup%5Bsunday%5D=0'
        . '&daycheckboxgroup%5Bsunday%5D=1'
        . '&daycheckboxgroup%5Bmonday%5D=0'
        . '&daycheckboxgroup%5Bmonday%5D=1'
        . '&daycheckboxgroup%5Btuesday%5D=0'
        . '&daycheckboxgroup%5Btuesday%5D=1'
        . '&daycheckboxgroup%5Bwednesday%5D=0'
        . '&daycheckboxgroup%5Bwednesday%5D=1'
        . '&daycheckboxgroup%5Bthursday%5D=0'
        . '&daycheckboxgroup%5Bthursday%5D=1'
        . '&daycheckboxgroup%5Bfriday%5D=0'
        . '&daycheckboxgroup%5Bfriday%5D=1'
        . '&fromtime=0.00'
        . '&totime=24.00'
        . '&semesters=_qf__force_multiselect_submission'
        . '&semesters%5B%5D=' . $semester
        . '&submitbutton=%D7%97%D7%99%D7%A4%D7%95%D7%A9';

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_COOKIE, 'MoodleSessionstudentsprod=' . $session_cookie);

    $html = curl_exec($ch);
    if ($html === false) {
        return [false, 'curl'];
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code != 200) {
        return [false, 'http'];
    }

    if (!is_valid_rishum_html($html)) {
        return [false, 'html'];
    }

    $prev_libxml_use_internal_errors = libxml_use_internal_errors(true);

    $dom = new \DOMDocument;
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new \DOMXPath($dom);

    libxml_use_internal_errors($prev_libxml_use_internal_errors);

    $courses = get_courses_from_xpath($xpath);
    if (count($courses) == 0) {
        return [false, 'no_courses'];
    }

    return [true, $courses];
}

function get_courses_next_page($ch, $session_cookie, $page) {
    $url = 'https://students.technion.ac.il/local/technionsearch/results?page=' . $page;

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, false);
    curl_setopt($ch, CURLOPT_COOKIE, 'MoodleSessionstudentsprod=' . $session_cookie);

    $html = curl_exec($ch);
    if ($html === false) {
        return [false, 'curl'];
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code != 200) {
        return [false, 'http'];
    }

    if (!is_valid_rishum_html($html)) {
        return [false, 'html'];
    }

    $prev_libxml_use_internal_errors = libxml_use_internal_errors(true);

    $dom = new \DOMDocument;
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new \DOMXPath($dom);

    libxml_use_internal_errors($prev_libxml_use_internal_errors);

    $courses = get_courses_from_xpath($xpath);
    if (count($courses) == 0) {
        assert(strpos($html, '<h3>לא נמצאו קורסים</h3>') !== false);
    }

    return [true, $courses];
}

function get_courses_from_xpath(\DOMXPath $xpath) {
    $class_rule = xpath_class_rule('list-group-item');
    $courses = $xpath->query("//section[@id='region-main']//a[$class_rule]");
    $courses = iterator_to_array($courses);
    $courses = array_map(function ($node) {
        $href = $node->getAttribute('href');
        $course = explode('/', $href, 3)[1];
        $course = str_pad($course, 6, '0', STR_PAD_LEFT);
        return $course;
    }, $courses);

    return $courses;
}

function download_courses($courses, $semester, $cache_dir, $course_cache_life, $simultaneous_downloads, $download_timeout) {
    if (!is_dir("$cache_dir/$semester")) {
        mkdir("$cache_dir/$semester");
    }
    $requests = array_map(function ($course) use ($semester, $cache_dir) {
        $suffix = $semester != '' ? "/$semester" : '';
        return [
            'url' => "https://students.technion.ac.il/local/technionsearch/course/$course$suffix",
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

    log_verbose("Downloading data of $requested_count courses:\n");

    foreach (array_chunk($requests, $simultaneous_downloads) as $i => $chunk) {
        multi_request($chunk, [CURLOPT_FAILONERROR => true, CURLOPT_TIMEOUT => $download_timeout]);
        log_verbose("Downloaded " . ($i * $simultaneous_downloads + count($chunk)) . "...\n");
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

    log_verbose("Done, $failed_count of $requested_count failed\n");
    return [$requested_count - $failed_count, $failed_count];
}

function is_valid_rishum_html($html) {
    // Verifies that the html response is not truncated. Helps detect partial server responses.
    if (substr(rtrim($html), -strlen('</html>')) !== '</html>') {
        return false;
    }

    if (strpos($html, 'Warning: mysqli') !== false) {
        return false;
    }

    return true;
}

function is_course_active_in_semester(\DOMXPath $xpath, $semester) {
    $semester_data = $xpath->query("//div[@id='s_$semester']");
    $semester_data = iterator_to_array($semester_data);
    return count($semester_data) > 0;
}

function get_course_info(\DOMDocument $dom, \DOMXPath $xpath, $semester) {
    $general = array_merge(
        get_course_general_info($dom, $xpath),
        get_course_semester_info($dom, $xpath, $semester)
    );

    $only_lectures = true;
    foreach ($general as $k => $v) {
        if (in_array($k, [
            'תרגיל',
            'סמינר/פרויקט',
            'מעבדה',
        ]) && $v != '0') {
            $only_lectures = false;
            break;
        }
    }
    $schedule = get_course_schedule($dom, $xpath, $semester, $only_lectures);

    $info = [
        'general' => $general,
        'schedule' => $schedule
    ];
    return $info;
}

function get_course_general_info(\DOMDocument $dom, \DOMXPath $xpath) {
    $info = [];

    $class_rule = xpath_class_rule('page-header-headings');
    $page_header = $xpath->query("//div[$class_rule]/h1");
    $page_header = iterator_to_array($page_header);
    assert(count($page_header) == 1);
    $page_header = $page_header[0];
    $page_header = trim($page_header->textContent);
    $matched = preg_match('#^(.*?)\s*-\s*(.*)$#u', $page_header, $matches);
    assert($matched);
    $info['מספר מקצוע'] = str_pad($matches[1], 6, '0', STR_PAD_LEFT);
    $info['שם מקצוע'] = $matches[2];

    $class_rule = xpath_class_rule('card-text');
    $card_text = $xpath->query("//div[@id='general_information']/p[$class_rule][position()=1]");
    $card_text = iterator_to_array($card_text);
    assert(count($card_text) == 1);
    $card_text = $card_text[0];
    $card_text = trim($card_text->textContent);
    $card_text = preg_replace_callback('#\s+#u', function ($matches) {
        return substr_count($matches[0], "\n") >= 2 ? "\n" : ' ';
    }, $card_text);
    $info['סילבוס'] = $card_text;

    $class_rule = xpath_class_rule('card-subtitle');
    $card_subtitle = $xpath->query("//div[@id='general_information']/h6[$class_rule]");
    $card_subtitle = iterator_to_array($card_subtitle);
    assert(count($card_subtitle) == 1);
    $card_subtitle = $card_subtitle[0];
    $card_subtitle = trim(DOMinnerHTML($card_subtitle));
    $matched = preg_match('#^פקולטה:\s*(.*?)\s*<br[^>]*>([\s\S]*)$#u', $card_subtitle, $matches);
    assert($matched);
    $info['פקולטה'] = $matches[1];
    $degrees = trim($matches[2]);
    if ($degrees != '') {
        $degrees = explode("\n", $degrees);
        $degrees_text = [];
        foreach ($degrees as $degree) {
            $text = trim($degree);
            assert(str_starts_with($text, '|'));
            $text = substr($text, strlen('|'));
            $degrees_text[] = trim($text);
        }
        $info['מסגרת לימודים'] = implode("\n", $degrees_text);
    }

    $class_rule = xpath_class_rule('card-title');
    $card_title = $xpath->query("//div[@id='general_information']/h5[$class_rule]");
    $card_title = iterator_to_array($card_title);
    $card_title = array_map(function ($node) {
        $text = trim($node->textContent);
        assert(in_array($text, [
            // 'מקצועות זהים',
            'מקצועות ללא זיכוי נוסף',
            // 'מקצועות ללא זיכוי נוסף (מוכלים)',
            'מקצועות ללא זיכוי נוסף (מכילים)',
            'מקצועות צמודים',
            'מקצועות קדם',
        ]));
        return $text;
    }, $card_title);

    $class_rule = xpath_class_rule('card-text');
    $card_text = $xpath->query("//div[@id='general_information']/p[$class_rule][position()>1]");
    $card_text = iterator_to_array($card_text);
    $card_text = array_map(function ($node) {
        $text = '';
        $children  = $node->childNodes;
        foreach ($children as $child) {
            if ($child->nodeName == 'span') {
                $course = trim($child->textContent);
            } else if ($child->nodeName == 'a') {
                $course = preg_replace('#^(\d+) - .*$#u', '$1', trim($child->textContent));
            } else {
                $text .= $child->textContent;
                continue;
            }

            $course = str_pad($course, 6, '0', STR_PAD_LEFT);
            assert(preg_match('#^\d{6}$#u', $course));

            $text .= $course;
        }

        $text = preg_replace('#\s+#u', ' ', trim($text));
        return $text;
    }, $card_text);
    assert(count($card_title) == count($card_text));

    $info = array_merge($info, array_combine($card_title, $card_text));

    return $info;
}

function get_course_semester_info(\DOMDocument $dom, \DOMXPath $xpath, $semester) {
    $info = [];

    $class_rule = xpath_class_rule('card-title');
    $card_title = $xpath->query("//div[@id='s_$semester']/h5[$class_rule]");
    $card_title = iterator_to_array($card_title);
    $card_title_nodes = [];
    foreach ($card_title as $node) {
        $text = trim($node->textContent);
        if (in_array($text, [
            'קבוצות רישום',
            'אין קבוצות רישום',
        ])) {
            continue;
        }

        assert(in_array($text, [
            'שעות שבועיות',
            'אחראים',
            'הערות',
            'מבחנים',
            'בחנים',
        ]), $text);
        $card_title_nodes[$text] = $node;
    }

    $hours = $card_title_nodes['שעות שבועיות'];
    do {
        $hours = $hours->nextSibling;
    } while ($hours->nodeName == '#text');
    assert($hours->nodeName == 'p' && $hours->getAttribute('class') == 'card-text');
    $hours = trim($hours->textContent);
    $hours = preg_split('#\s*•\s*#u', $hours);
    foreach ($hours as $item) {
        $mapping = [
            'נקודות אקדמיות' => 'נקודות',
            'שעות הרצאה' => 'הרצאה',
            'שעות תרגול' => 'תרגיל',
            'שעות פרוייקט' => 'סמינר/פרויקט',
            'שעות מעבדה' => 'מעבדה',
        ];
        $info_key = null;
        $info_value = null;
        foreach ($mapping as $k => $v) {
            $suffix = ' ' . $k;
            if (str_ends_with($item, $suffix)) {
                $info_key = $v;
                $info_value = substr($item, 0, -strlen($suffix));
                break;
            }
        }
        assert(isset($info_key, $info_value));
        assert(preg_match('#^\d+(\.5)?$#u', $info_value));
        $info[$info_key] = $info_value;
    }

    if (isset($card_title_nodes['אחראים'])) {
        $staff = $card_title_nodes['אחראים'];
        do {
            $staff = $staff->nextSibling;
        } while ($staff->nodeName == '#text');
        assert($staff->nodeName == 'p' && $staff->getAttribute('class') == 'card-text');
        $staff = trim($staff->textContent);
        $info['אחראים'] = $staff;
    }

    if (isset($card_title_nodes['הערות'])) {
        $notes = $card_title_nodes['הערות'];
        do {
            $notes = $notes->nextSibling;
        } while ($notes->nodeName == '#text');
        assert($notes->nodeName == 'ul');
        $note_text = [];
        for ($note = $notes->firstChild; $note; $note = $note->nextSibling) {
            if ($note->nodeName == '#text') {
                continue;
            }
            assert($note->nodeName == 'li');
            $note_text[] = trim($note->textContent);
        }
        $note_text = implode("\n====================\n", $note_text);
        $info['הערות'] = $note_text;
    }

    $exam_types = ['מבחנים', 'בחנים'];
    foreach ($exam_types as $exam_type) {
        if (isset($card_title_nodes[$exam_type])) {
            $exams = $card_title_nodes[$exam_type];
            $exam = $exams->nextSibling;
            while ($exam->nodeName == '#text') {
                $exam = $exam->nextSibling;
            }
            while (!in_array($exam->nodeName, ['br', 'h5'])) {
                assert($exam->nodeName == 'span');
                $text = trim($exam->textContent);
                do {
                    $exam = $exam->nextSibling;
                } while ($exam->nodeName == '#text');

                while ($exam->nodeName == 'ul') {
                    $text .= "\n" . trim($exam->textContent);
                    do {
                        $exam = $exam->nextSibling;
                    } while ($exam->nodeName == '#text');
                }

                $p = '#^(מועד [א-ת])(?: \((.*?)\))?: #u';
                $matched = preg_match($p, $text, $matches);
                assert($matched);
                $info_key = $matches[1];
                $text = preg_replace($p, '', $text);
                if (isset($matches[2])) {
                    $text .= "\n" . $matches[2];
                }

                if ($exam_type == 'בחנים') {
                    assert(in_array($info_key, [
                        'מועד א',
                        'מועד ב',
                        'מועד ג',
                        'מועד ד',
                        'מועד ה',
                    ]));
                    $info_key = 'בוחן ' . $info_key;
                } else {
                    assert(in_array($info_key, [
                        'מועד א',
                        'מועד ב',
                    ]));
                }

                if (isset($info[$info_key])) {
                    $info[$info_key] .= "\n\n" . $text;
                } else {
                    $info[$info_key] = $text;
                }
            }
        }
    }

    return $info;
}

function get_course_schedule(\DOMDocument $dom, \DOMXPath $xpath, $semester, $only_lectures) {
    $class_rule = xpath_class_rule('card-title');
    $card_title = $xpath->query("//div[@id='s_$semester']/h5[$class_rule]");
    $card_title = iterator_to_array($card_title);
    $schedule_node = null;
    $empty_schedule_node = false;
    foreach ($card_title as $node) {
        $text = trim($node->textContent);
        if ($text == 'קבוצות רישום') {
            assert(!$empty_schedule_node);
            $schedule_node = $node;
        } else if ($text == 'אין קבוצות רישום') {
            assert(!$schedule_node);
            $empty_schedule_node = true;
        }
    }
    assert($schedule_node || $empty_schedule_node);
    if ($empty_schedule_node) {
        return [];
    }

    $schedule = [];

    do {
        $schedule_node = $schedule_node->nextSibling;
    } while ($schedule_node->nodeName == '#text');
    assert($schedule_node->nodeName == 'div' && $schedule_node->getAttribute('class') == 'list-group');

    for ($row = $schedule_node->firstChild; $row; $row = $row->nextSibling) {
        if ($row->nodeName == '#text') {
            continue;
        }
        assert($row->nodeName == 'span' && preg_match('#(^|\s)list-group-item($|\s)#u', $row->getAttribute('class')));

        $group = $xpath->query(".//td[@style='width: 15%;']//span[@style='font-size: 250%;']", $row);
        $group = iterator_to_array($group);
        assert(count($group) == 1);
        $group = $group[0];
        $group = trim($group->textContent);

        $subrows = $xpath->query(".//td[@style='width: 85%;']//tr", $row);
        $subrows = iterator_to_array($subrows);
        assert(count($subrows) > 0);
        foreach ($subrows as $subrow) {
            $item = [
                'קבוצה' => $group,
            ];

            $subcolumns = $xpath->query(".//td", $subrow);
            $subcolumns = iterator_to_array($subcolumns);
            assert(count($subcolumns) == 6);

            $item['סוג'] = trim($subcolumns[0]->textContent);
            $item['יום'] = trim($subcolumns[1]->textContent);
            $item['שעה'] = trim($subcolumns[2]->textContent);

            $location = $subcolumns[3]->firstChild;
            while ($location == '#text') {
                $location = $location->nextSibling;
            }
            assert($location->nodeName == 'span');
            $item['בניין'] = trim($location->textContent);

            $location = $location->nextSibling;
            if ($location) {
                assert($location->nodeName == '#text');
                $item['חדר'] = trim($location->textContent);
                assert(!$location->nextSibling);
            } else {
                $item['חדר'] = '';
            }

            $staff_text = [];
            for ($staff = $subcolumns[4]->firstChild; $staff; $staff = $staff->nextSibling) {
                if ($staff->nodeName == '#text') {
                    continue;
                }
                assert($staff->nodeName == 'span');
                $text = trim($staff->textContent);
                assert(str_starts_with($text, '| '));
                $text = substr($text, strlen('| '));
                $staff_text[] = trim($text);
            }
            $staff_text = implode("\n", $staff_text);
            $item['מרצה/מתרגל'] = $staff_text;

            $schedule[] = $item;
        }
    }

    $lectures = [];
    foreach ($schedule as &$item) {
        $group = $item['קבוצה'];
        assert($item['סוג'] == 'הרצאה' || !$only_lectures);
        if ($item['סוג'] == 'הרצאה' && !$only_lectures) {
            $id = substr($group, 0, -1) . '0';
            $item['מס.'] = $id;

            // The code below is only for sanity check.
            $found_item = false;
            $found_match = false;
            foreach ($lectures as $lecture) {
                if ($item['קבוצה'] != $lecture['קבוצה'] && $item['מס.'] == $lecture['מס.']) {
                    $found_item = true;
                    $keys = [
                        'סוג',
                        'יום',
                        'שעה',
                        'בניין',
                        'חדר',
                        'מרצה/מתרגל',
                    ];
                    $match = true;
                    foreach ($keys as $key) {
                        if ($item[$key] != $lecture[$key]) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $found_match = true;
                    }
                }
            }

            if (!$found_item) {
                $lectures[] = $item;
            } else {
                assert($found_match);
            }
        } else {
            $item['מס.'] = $group;
        }
    }
    unset($item);

    return $schedule;
}

function log_verbose($msg) {
    global $course_info_fetcher_verbose;

    if ($course_info_fetcher_verbose) {
        echo $msg;
    }
}

function xpath_class_rule($class) {
    return "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";
}

// https://stackoverflow.com/a/2087136
function DOMinnerHTML(\DOMNode $element) {
    $innerHTML = '';
    $children  = $element->childNodes;

    foreach ($children as $child) {
        $innerHTML .= $element->ownerDocument->saveHTML($child);
    }

    return $innerHTML;
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
