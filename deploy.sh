#!/bin/bash -e

if [ -z "$1" ]; then
	out_dir=.
else
	out_dir=$1
fi

semester_1=202203
semester_2=202301
semester_3=202302
semester_next=202303

function technion_url_get {
	local url=$1
	if [[ -z "${COURSE_INFO_FETCHER_PROXY_URL}" ]] && [[ -z "${COURSE_INFO_FETCHER_PROXY_AUTH}" ]]; then
		curl -s -x "$COURSE_INFO_FETCHER_PROXY" "$url"
	else
		curl -s "$COURSE_INFO_FETCHER_PROXY_URL" --header "Proxy-Auth: $COURSE_INFO_FETCHER_PROXY_AUTH" --header "Proxy-Target-URL: $url"
	fi
}

function semester_available {
	local semester=$1
	echo Checking semester $semester availability...

	technion_url_get 'https://students.technion.ac.il/local/technionsearch/search' | grep -qF 'name="semesterscheckboxgroup['$semester']"'
}

function fetch_semester {
	local semester=$1
	echo Fetching semester $semester...

	local out_dir=$2

	local courses_file=courses_$semester

	php courses_to_json.php --semester "$semester" --verbose --simultaneous_downloads 16 || {
		cd ..
		echo courses_to_json failed
		return 1
	}

	local src_file=$courses_file.json
	local dest_file_min_js=$out_dir/$courses_file.min.js
	local dest_file=$out_dir/$courses_file.json

	echo -n "var courses_from_rishum = JSON.parse('" > $dest_file_min_js
	local php_cmd="echo addcslashes(file_get_contents('$src_file'), '\\\\\\'');"
	php -r "$php_cmd" >> $dest_file_min_js
	echo -n "')" >> $dest_file_min_js

	local php_cmd="echo json_encode(json_decode(file_get_contents('$src_file')), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);"
	php -r "$php_cmd" > $dest_file

	return 0
}

# Check semester availability.
semester_available $semester_1 || exit 1
semester_available $semester_2 || exit 1
semester_available $semester_3 || exit 1
semester_available $semester_next && exit 1

# Fetch last three semesters.
fetch_semester $semester_1 "$out_dir" || exit 1
fetch_semester $semester_2 "$out_dir" || exit 1
fetch_semester $semester_3 "$out_dir" || exit 1

echo Done

exit 0
