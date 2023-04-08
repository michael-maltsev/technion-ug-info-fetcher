<?php

namespace course_info_fetcher;

function fetch($options = []) {
    global $course_info_fetcher_verbose;

    $cache_dir = $options['cache_dir'] ?? 'course_info_cache';
    $semester = $options['semester'] ?? null;
    $course_cache_life = intval($options['course_cache_life'] ?? 60*60*24*365*10);
    $simultaneous_downloads = intval($options['simultaneous_downloads'] ?? 64);
    $course_info_fetcher_verbose = isset($options['verbose']);

    if (!$semester) {
        log_verbose("Error: Semester not specified\n");
        return false;
    }

    if (!is_dir($cache_dir)) {
        mkdir($cache_dir);
    }

    log_verbose("Downloading list of courses...\n");

    $courses = get_courses_from_rishum($semester, $simultaneous_downloads);
    if ($courses === false) {
        log_verbose("Error: Couldn't get list of courses\n");
        return false;
    }

    log_verbose("Downloading course data...\n");

    list($downloaded, $failed) = download_courses(
        $courses, $cache_dir, $course_cache_life, $simultaneous_downloads);

    while ($failed > 0) {
        log_verbose("Re-trying download for $failed failed courses...\n");
        sleep(10);
        list($downloaded_new, $failed) = download_courses(
            $courses, $cache_dir, $course_cache_life, $simultaneous_downloads);

        $downloaded += $downloaded_new;
    }

    log_verbose("Parsing downloaded data...\n");

    //$debug_filename = "$cache_dir/_debug.txt";
    //file_put_contents($debug_filename, '');

    $prev_libxml_use_internal_errors = libxml_use_internal_errors(true);

    $fetched_info = [];
    foreach ($courses as $course) {
        $html = file_get_contents("$cache_dir/$course.html");

        // Replace invalid UTF-8 characters.
        // https://stackoverflow.com/a/8215387
        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');

        $dom = new \DOMDocument;
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        if (!is_course_active_in_semester($course, $xpath, $semester)) {
            continue;
        }

        $info = get_course_info($course, $dom, $xpath, $semester);

        //file_put_contents($debug_filename, print_r($info, true), FILE_APPEND);
        $fetched_info[] = $info;
    }

    libxml_use_internal_errors($prev_libxml_use_internal_errors);

    return [
        'semester' => $semester,
        'info' => $fetched_info,
        'downloaded' => $downloaded,
    ];
}

function get_courses_from_rishum($semester, $simultaneous_downloads) {
    $ch = curl_init();

    $session_params = get_courses_session_params($ch);
    if ($session_params === false) {
        log_verbose("Error: Couldn't get session params\n");
        return false;
    }

    $session_cookie = $session_params['session_cookie'];
    $sesskey = $session_params['sesskey'];

    log_verbose("Getting list of courses from Rishum:\n");
    log_verbose("Page 1...\n");

    $result = get_courses_first_page($ch, $session_cookie, $sesskey, $semester);
    while ($result === false) {
        log_verbose("Re-trying...\n");
        sleep(10);
        $result = get_courses_first_page($ch, $session_cookie, $sesskey, $semester);
    }

    $courses = $result['courses'];
    $num_pages = $result['num_pages'];

    if (count($courses) == 0) {
        log_verbose("Error: No courses on first page\n");
        return false;
    }

    $chs = [];
    $page = 1;
    $reached_end = false;
    while (!$reached_end) {
        log_verbose("Page " . ($page + 1) . "...\n");

        $result = get_courses_next_pages($chs, $session_cookie, $page, $simultaneous_downloads);
        while ($result === false) {
            log_verbose("Re-trying...\n");
            $chs = [];
            sleep(10);
            $result = get_courses_next_pages($chs, $session_cookie, $page, $simultaneous_downloads);
        }

        list($pages_processed, $reached_end, $iter_courses) = $result;

        if ($pages_processed > 0) {
            ensure(count($iter_courses) > 0);
            $courses = array_unique(array_merge($courses, $iter_courses));
            $page += $pages_processed;
        } else {
            ensure($reached_end);
            ensure(count($iter_courses) == 0);
        }
    }

    ensure($page == $num_pages);

    log_verbose("Got list of " . count($courses) . " courses from $page pages\n");

    sort($courses);
    return $courses;
}

function get_courses_session_params($ch) {
    $url = 'https://students.technion.ac.il/local/technionsearch/search';

    curl_reset($ch);
    curl_setopt_array($ch, initial_curl_options($url));
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $session_cookie = getenv('MOODLE_SESSIONSTUDENTSPROD', true);
    if ($session_cookie) {
        curl_setopt($ch, CURLOPT_COOKIE, 'MoodleSessionstudentsprod=' . $session_cookie);
    }

    // https://stackoverflow.com/a/25098798
    $cookies = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header_line) use (&$cookies) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]+)/i', $header_line, $matches)) {
            $cookies[$matches[1]] = $matches[2];
        }
        return strlen($header_line); // needed by curl
    });

    $html = curl_exec($ch);
    if ($html === false) {
        log_verbose("Error: " . curl_errno($ch) . " " . curl_error($ch) . "\n");
        return false;
    }

    if (!$session_cookie) {
        if (!isset($cookies['MoodleSessionstudentsprod'])) {
            log_verbose("Error: No session cookie received\n");
            return false;
        }

        $session_cookie = $cookies['MoodleSessionstudentsprod'];
    }

    $p = '#\nM\.cfg = {"wwwroot":"https:\\\\/\\\\/students\.technion\.ac\.il","sesskey":"([0-9a-zA-Z]+)"#u';
    if (!preg_match($p, $html, $matches)) {
        log_verbose("Error: sesskey value not found\n");
        return false;
    }

    $sesskey = $matches[1];

    return [
        'session_cookie' => $session_cookie,
        'sesskey' => $sesskey,
    ];
}

function get_courses_first_page($ch, $session_cookie, $sesskey, $semester) {
    $url = 'https://students.technion.ac.il/local/technionsearch/search';

    $semester_add = function ($semester, $add) {
        ensure(preg_match('/^\d{4}0[1-3]$/', $semester));
        $year = intval(substr($semester, 0, 4));
        $season = intval(substr($semester, 4));
        $season += $add;
        while ($season < 1) {
            $year--;
            $season += 3;
        }
        while ($season > 3) {
            $year++;
            $season -= 3;
        }

        return "{$year}0{$season}";
    };

    $post_fields = 'mform_isexpanded_id_advance_filters=0'
        . '&sesskey=' . $sesskey
        . '&_qf__local_technionsearch_form_search_advance=1'
        . '&course_name='
        . '&semesterscheckboxgroup%5B' . $semester_add($semester, -3) . '%5D=0'
        . '&semesterscheckboxgroup%5B' . $semester_add($semester, -2) . '%5D=0'
        . '&semesterscheckboxgroup%5B' . $semester_add($semester, -1) . '%5D=0'
        . '&semesterscheckboxgroup%5B' . $semester . '%5D=1'
        . '&semesterscheckboxgroup%5B' . $semester_add($semester, 1) . '%5D=0'
        . '&semesterscheckboxgroup%5B' . $semester_add($semester, 2) . '%5D=0'
        . '&semesterscheckboxgroup%5B' . $semester_add($semester, 3) . '%5D=0'
        . '&faculties=_qf__force_multiselect_submission'
        . '&lecturer_name=_qf__force_multiselect_submission'
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
        . '&hours_group_filter%5Bfromtime%5D=7.00'
        . '&hours_group_filter%5Btotime%5D=23.30'
        . '&credit_group_filter%5Bmin_points%5D=0.0'
        . '&credit_group_filter%5Bmax_points%5D=20.0'
        . '&academic_level_group%5B1%5D=0'
        . '&academic_level_group%5B1%5D=1'
        . '&academic_level_group%5B2%5D=0'
        . '&academic_level_group%5B2%5D=1'
        . '&academic_level_group%5B3%5D=0'
        . '&academic_level_group%5B3%5D=1'
        . '&has_english_lessons=0'
        . '&submitbutton=%D7%97%D7%99%D7%A4%D7%95%D7%A9';

    curl_reset($ch);
    curl_setopt_array($ch, initial_curl_options($url));
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_COOKIE, 'MoodleSessionstudentsprod=' . $session_cookie);

    $html = curl_exec($ch);
    if ($html === false) {
        log_verbose("Error: " . curl_errno($ch) . " " . curl_error($ch) . "\n");
        return false;
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code != 200) {
        log_verbose("Error: Unexpected server code $code\n");
        return false;
    }

    if (!is_valid_rishum_html($html)) {
        log_verbose("Error: Invalid html\n");
        return false;
    }

    $prev_libxml_use_internal_errors = libxml_use_internal_errors(true);

    $dom = new \DOMDocument;
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new \DOMXPath($dom);

    libxml_use_internal_errors($prev_libxml_use_internal_errors);

    return [
        'courses' => get_courses_from_xpath($xpath),
        'num_pages' => get_num_pages_from_xpath($xpath),
    ];
}

function get_courses_next_pages($chs, $session_cookie, $page_start, $page_amount) {
    $urls = [];
    for ($i = 0; $i < $page_amount; $i++) {
        $urls[] = 'https://students.technion.ac.il/local/technionsearch/search?page=' . ($page_start + $i);
    }

    $results = multi_request($urls, [
        CURLOPT_FAILONERROR => true,
        CURLOPT_COOKIE => 'MoodleSessionstudentsprod=' . $session_cookie,
    ], $chs);

    $pages_processed = 0;
    $reached_end = false;
    $courses = [];
    foreach ($results as $html) {
        if (!$html || !is_valid_rishum_html($html)) {
            break;
        }

        $prev_libxml_use_internal_errors = libxml_use_internal_errors(true);

        $dom = new \DOMDocument;
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        libxml_use_internal_errors($prev_libxml_use_internal_errors);

        $iter_courses = get_courses_from_xpath($xpath);

        if (count($iter_courses) == 0) {
            ensure(strpos($html, '<h2>אין כלום להציג</h2>') !== false);
            $reached_end = true;
            break;
        }

        $pages_processed++;
        $courses = array_unique(array_merge($courses, $iter_courses));
    }

    if ($pages_processed == 0 && !$reached_end) {
        return false;
    }

    return [$pages_processed, $reached_end, $courses];
}

function get_courses_from_xpath(\DOMXPath $xpath) {
    $starts_with_rule = "starts-with(@id, 'courses_results-table_r')";
    $ends_with_rule = xpath_ends_with_rule('id', '_c1');
    $courses = $xpath->query("//section[@id='region-main']//td[$starts_with_rule and $ends_with_rule]/a");
    $courses = iterator_to_array($courses);
    $courses = array_map(function ($node) {
        $href = $node->getAttribute('href');
        $matched = preg_match('#/course/(\d+)$#u', $href, $matches);
        ensure($matched);
        ensure(preg_match('/^\d+$/u', $matches[1]));
        return str_pad($matches[1], 6, '0', STR_PAD_LEFT);
    }, $courses);

    return $courses;
}

function get_num_pages_from_xpath(\DOMXPath $xpath) {
    $class_rule = xpath_class_rule('pagination');
    $items = $xpath->query("//section[@id='region-main']//nav[$class_rule]//li[@data-page-number]");
    $items = iterator_to_array($items);
    $items = array_map(function ($node) {
        $data_page_number = $node->getAttribute('data-page-number');
        ensure(preg_match('/^\d+$/u', $data_page_number));
        return intval($data_page_number);
    }, $items);

    return count($items) > 0 ? max($items) : 1;
}

function download_courses($courses, $cache_dir, $course_cache_life, $simultaneous_downloads) {
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir);
    }
    $requests = array_map(function ($course) use ($cache_dir) {
        return [
            'url' => "https://students.technion.ac.il/local/technionsearch/course/$course",
            'filename' => "$cache_dir/$course.html",
        ];
    }, $courses);

    $min_valid_cache_time = time() - $course_cache_life;
    $should_request_course = function ($request) use ($min_valid_cache_time) {
        return
            !is_file($request['filename']) ||
            filemtime($request['filename']) < $min_valid_cache_time ||
            !is_valid_rishum_course_html(file_get_contents($request['filename']));
    };

    $requests = array_filter($requests, $should_request_course);
    $requested_count = count($requests);

    log_verbose("Downloading data of $requested_count courses:\n");

    $chs = [];
    foreach (array_chunk($requests, $simultaneous_downloads) as $i => $chunk) {
        multi_request($chunk, [
            CURLOPT_FAILONERROR => true,
        ], $chs);
        log_verbose("Downloaded " . ($i * $simultaneous_downloads + count($chunk)) . "...\n");
    }
    unset($chs); // causes file data to be flushed

    $requests = array_filter($requests, $should_request_course);
    $failed_count = count($requests);

    log_verbose("Done, $failed_count of $requested_count failed\n");
    return [$requested_count - $failed_count, $failed_count];
}

function is_valid_rishum_html($html) {
    // Verifies that the html response is not truncated. Helps detect partial server responses.
    if (substr(rtrim($html), -strlen('</html>')) !== '</html>') {
        return false;
    }

    if (strpos($html, 'generalexceptionmessage') !== false) {
        return false;
    }

    return true;
}

function is_valid_rishum_course_html($html) {
    if (!is_valid_rishum_html($html)) {
        return false;
    }

    $p = '#<meta\s+property="page_url"\s+name="page_url"\s+content="https://students\.technion\.ac\.il/local/technionsearch/course/\d+/\d+"\s+/>#';
    if (!preg_match($p, $html)) {
        return false;
    }

    return true;
}

function is_course_active_in_semester($course, \DOMXPath $xpath, $semester) {
    $semester_data = $xpath->query("//div[@id='s_$semester']");
    $semester_data = iterator_to_array($semester_data);
    return count($semester_data) > 0;
}

function get_course_info($course, \DOMDocument $dom, \DOMXPath $xpath, $semester) {
    $general = array_merge(
        get_course_general_info($course, $dom, $xpath),
        get_course_semester_info($course, $dom, $xpath, $semester)
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
    $schedule = get_course_schedule($course, $dom, $xpath, $semester, $only_lectures);

    $info = [
        'general' => $general,
        'schedule' => $schedule,
    ];
    return $info;
}

function get_course_general_info($course, \DOMDocument $dom, \DOMXPath $xpath) {
    $info = [];

    $class_rule = xpath_class_rule('page-header-headings');
    $page_header = $xpath->query("//div[$class_rule]/h1");
    $page_header = iterator_to_array($page_header);
    ensure(count($page_header) == 1);
    $page_header = $page_header[0];
    $page_header = trim($page_header->textContent);
    $matched = preg_match('#^(.*?)\s*-\s*(.*)$#u', $page_header, $matches);
    ensure($matched);
    ensure($course == str_pad($matches[1], 6, '0', STR_PAD_LEFT));
    $info['מספר מקצוע'] = $course;
    $info['שם מקצוע'] = $matches[2];

    $class_rule = xpath_class_rule('card-text');
    $card_text = $xpath->query("//div[@id='general_information']/p[$class_rule][position()=1]");
    $card_text = iterator_to_array($card_text);
    ensure(count($card_text) == 1);
    $card_text = $card_text[0];
    $card_text = trim($card_text->textContent);
    $card_text = preg_replace_callback('#\s+#u', function ($matches) {
        return substr_count($matches[0], "\n") >= 2 ? "\n" : ' ';
    }, $card_text);
    $info['סילבוס'] = $card_text;

    $class_rule = xpath_class_rule('card-subtitle');
    $card_subtitle = $xpath->query("//div[@id='general_information']/h6[$class_rule]");
    $card_subtitle = iterator_to_array($card_subtitle);
    ensure(count($card_subtitle) == 1);
    $card_subtitle = $card_subtitle[0];
    $card_subtitle = trim(DOMinnerHTML($card_subtitle));
    $matched = preg_match('#^פקולטה:\s*(.*?)\s*<br[^>]*>([\s\S]*)$#u', $card_subtitle, $matches);
    ensure($matched);
    $info['פקולטה'] = $matches[1];
    $degrees = trim($matches[2]);
    if ($degrees != '') {
        $degrees = explode("\n", $degrees);
        $degrees_text = [];
        foreach ($degrees as $degree) {
            $text = trim($degree);
            ensure(str_starts_with($text, '|'));
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
        ensure(in_array($text, [
            // 'מקצועות זהים',
            'מקצועות ללא זיכוי נוסף',
            'מקצועות ללא זיכוי נוסף (מוכלים)',
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
            ensure(preg_match('#^\d{6}$#u', $course));

            $text .= $course;
        }

        $text = preg_replace('#\s+#u', ' ', trim($text));
        return $text;
    }, $card_text);
    ensure(count($card_title) == count($card_text));

    $info = array_merge($info, array_combine($card_title, $card_text));

    return $info;
}

function get_course_semester_info($course, \DOMDocument $dom, \DOMXPath $xpath, $semester) {
    $info = [];

    $class_rule = xpath_class_rule('card-title');
    $card_title = $xpath->query("//div[@id='s_$semester']/h5[$class_rule]");
    $card_title = iterator_to_array($card_title);
    $card_title_nodes = [];
    foreach ($card_title as $node) {
        $text = trim($node->textContent);
        if (in_array($text, [
            'ניווט לדף המקצוע',
            'קבוצות רישום',
            'אין קבוצות רישום',
        ])) {
            continue;
        }

        ensure(in_array($text, [
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
    ensure($hours->nodeName == 'p' && $hours->getAttribute('class') == 'card-text');
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
        ensure(isset($info_key, $info_value));
        ensure(preg_match('#^\d+(\.5)?$#u', $info_value));
        $info[$info_key] = $info_value;
    }

    if (isset($card_title_nodes['אחראים'])) {
        $staff = $card_title_nodes['אחראים'];
        do {
            $staff = $staff->nextSibling;
        } while ($staff->nodeName == '#text');
        ensure($staff->nodeName == 'p' && $staff->getAttribute('class') == 'card-text');
        $staff = trim($staff->textContent);
        $info['אחראים'] = $staff;
    }

    if (isset($card_title_nodes['הערות'])) {
        $notes = $card_title_nodes['הערות'];
        do {
            $notes = $notes->nextSibling;
        } while ($notes->nodeName == '#text');
        ensure($notes->nodeName == 'ul');
        $note_text = [];
        for ($note = $notes->firstChild; $note; $note = $note->nextSibling) {
            if ($note->nodeName == '#text') {
                continue;
            }
            ensure($note->nodeName == 'li');
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
                ensure($exam->nodeName == 'span');
                $text = trim($exam->textContent);
                do {
                    $exam = $exam->nextSibling;
                } while ($exam->nodeName == '#text');

                while ($exam->nodeName == 'ul') {
                    for ($li = $exam->firstChild; $li; $li = $li->nextSibling) {
                        if ($li->nodeName == '#text') {
                            continue;
                        }
                        ensure($li->nodeName == 'li');
                        $text .= "\n" . trim($li->textContent);
                    };

                    do {
                        $exam = $exam->nextSibling;
                    } while ($exam->nodeName == '#text');
                }

                // Skip unsupported entries.
                // An example spotted in the wild:
                // Semester 202101, course 014505, entry:
                // [[session_32]]: 03-03-2022 13:00 - 16:00
                if (preg_match('#\[\[session_\d+]\]\: #u', $text)) {
                    continue;
                }

                $p = '#^(מועד [א-ת])(?: \((.*?)\))?: #u';
                $matched = preg_match($p, $text, $matches);
                ensure($matched);
                $info_key = $matches[1];
                $text = preg_replace($p, '', $text);
                if (isset($matches[2])) {
                    $text .= "\n" . $matches[2];
                }

                if ($exam_type == 'בחנים') {
                    ensure(in_array($info_key, [
                        'מועד א',
                        'מועד ב',
                        'מועד ג',
                        'מועד ד',
                        'מועד ה',
                    ]));
                    $info_key = 'בוחן ' . $info_key;
                } else {
                    ensure(in_array($info_key, [
                        'מועד א',
                        'מועד ב',
                        'מועד ג',
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

function get_course_schedule($course, \DOMDocument $dom, \DOMXPath $xpath, $semester, $only_lectures) {
    $class_rule = xpath_class_rule('card-title');
    $card_title = $xpath->query("//div[@id='s_$semester']/h5[$class_rule]");
    $card_title = iterator_to_array($card_title);
    $schedule_node = null;
    $empty_schedule_node = false;
    foreach ($card_title as $node) {
        $text = trim($node->textContent);
        if ($text == 'קבוצות רישום') {
            ensure(!$empty_schedule_node);
            $schedule_node = $node;
        } else if ($text == 'אין קבוצות רישום') {
            ensure(!$schedule_node);
            $empty_schedule_node = true;
        }
    }
    ensure($schedule_node || $empty_schedule_node);
    if ($empty_schedule_node) {
        return [];
    }

    $schedule = [];

    do {
        $schedule_node = $schedule_node->nextSibling;
    } while ($schedule_node->nodeName == '#text');
    ensure($schedule_node->nodeName == 'div' && $schedule_node->getAttribute('class') == 'list-group');

    for ($row = $schedule_node->firstChild; $row; $row = $row->nextSibling) {
        if ($row->nodeName == '#text' || $row->nodeName == '#comment') {
            continue;
        }
        ensure($row->nodeName == 'span' && preg_match('#(^|\s)list-group-item($|\s)#u', $row->getAttribute('class')));

        $group = $xpath->query(".//td[@style='width: 15%;']//span[@style='font-size: 250%;']", $row);
        $group = iterator_to_array($group);
        ensure(count($group) == 1);
        $group = $group[0];
        $group = trim($group->textContent);

        $subrows = $xpath->query(".//td[@style='width: 85%;']//tr", $row);
        $subrows = iterator_to_array($subrows);
        ensure(count($subrows) > 0);
        foreach ($subrows as $subrow) {
            $item = [
                'קבוצה' => $group,
            ];

            $subcolumns = $xpath->query(".//td", $subrow);
            $subcolumns = iterator_to_array($subcolumns);
            ensure(count($subcolumns) == 6);

            $item['סוג'] = trim($subcolumns[0]->textContent);
            $item['יום'] = trim($subcolumns[1]->textContent);
            $item['שעה'] = trim($subcolumns[2]->textContent);

            if ($item['סוג'] == 'קבוצת רישום') {
                ensure($item['יום'] == '');
                ensure($item['שעה'] == 'אין מידע אודות שעות לימוד');
                continue;
            }

            $location = $subcolumns[3]->firstChild;
            while ($location == '#text') {
                $location = $location->nextSibling;
            }
            ensure($location->nodeName == 'span');
            $item['בניין'] = trim($location->textContent);

            $location = $location->nextSibling;
            if ($location) {
                ensure($location->nodeName == '#text');
                $item['חדר'] = trim($location->textContent);
                ensure(!$location->nextSibling);
            } else {
                $item['חדר'] = '';
            }

            $staff_text = [];
            for ($staff = $subcolumns[4]->firstChild; $staff; $staff = $staff->nextSibling) {
                if ($staff->nodeName == '#text') {
                    continue;
                }
                ensure($staff->nodeName == 'span');
                $text = trim($staff->textContent);
                ensure(str_starts_with($text, '| '));
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
        ensure($item['סוג'] == 'הרצאה' || !$only_lectures);
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
                ensure($found_match);
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

// https://stackoverflow.com/a/40935676
function xpath_ends_with_rule($tagname, $str) {
    return "substring(@$tagname, string-length(@$tagname) - string-length('$str') + 1) = '$str'";
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
function utf8_strrev($str) {
    preg_match_all('/./us', $str, $ar);
    return join('', array_reverse($ar[0]));
}

function ensure($check, $message = '') {
    if (!$check) {
        $bt = debug_backtrace();
        $caller = array_shift($bt);
        throw new \ErrorException($message, 0, E_ERROR, $caller['file'], $caller['line']);
    }
}

// https://www.phpied.com/simultaneuos-http-requests-in-php-with-curl/
// http://php.net/manual/en/function.curl-multi-select.php#115381
function multi_request($data, $options = [], &$curly = []) {
    // data to be returned
    $result = [];

    // multi handle
    $mh = curl_multi_init();

    // loop through $data and create curl handles
    // then add them to the multi-handle
    foreach ($data as $id => $d) {
        if (isset($curly[$id])) {
            curl_reset($curly[$id]);
        } else {
            $curly[$id] = curl_init();
        }

        $url = (is_array($d) && !empty($d['url'])) ? $d['url'] : $d;

        curl_setopt_array($curly[$id], initial_curl_options($url));
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
    foreach ($data as $id => $d) {
        if (!is_array($d) || empty($d['filename'])) {
            $result[$id] = curl_multi_getcontent($curly[$id]);
        }
        curl_multi_remove_handle($mh, $curly[$id]);
    }

    // all done
    curl_multi_close($mh);

    return $result;
}

function initial_curl_options($url) {
    $options = [
        CURLOPT_TIMEOUT => 60,
    ];

    $proxy_url = getenv('COURSE_INFO_FETCHER_PROXY_URL', true);
    $proxy_auth = getenv('COURSE_INFO_FETCHER_PROXY_AUTH', true);
    if ($proxy_url && $proxy_auth) {
        $options[CURLOPT_URL] = $proxy_url;
        $options[CURLOPT_HTTPHEADER] = [
            'Proxy-Auth: ' . $proxy_auth,
            'Proxy-Target-URL: ' . $url,
        ];
    } else {
        $options[CURLOPT_URL] = $url;

        $proxy = getenv('COURSE_INFO_FETCHER_PROXY', true);
        if ($proxy) {
            $options[CURLOPT_PROXY] = $proxy;
        }
    }

    return $options;
}
