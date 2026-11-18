#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
suite=$(pwd)/tests
DOCUMENT_ROOT=$(mktemp -d)
export DOCUMENT_ROOT
trap 'rm -rf "$DOCUMENT_ROOT"' EXIT
mkdir "$DOCUMENT_ROOT/compiled"
cd "$DOCUMENT_ROOT"
printf 'body { color: teal; }' > first.css
printf 'h1 { color: navy; }' > second.css
printf 'var a = 17;' > first.js
printf 'var b = 23;' > second.js
render() { php -d display_errors=stderr "$suite/render.php" "$@" > page; }
contains() { grep -Fq "$1" "$2" || { echo "Missing $1 in $2" >&2; exit 1; }; }
check_urls() {
    for ext in css js; do
        path=$(grep -Eo "/cache/app$1\\.$ext" page)
        test -s ".$path"
        case "$ext" in
            css) contains 'teal' ".$path"; contains 'navy' ".$path";;
            js) contains '17' ".$path"; contains '23' ".$path";;
        esac
    done
}
unset TEST_VERSION
render
for ext in css js; do
    contains "/first.$ext?" page
    contains "/second.$ext?" page
done
render
check_urls '_[0-9]{14}'
cp page timestamp-page
export TEST_VERSION=hash
render timestamp
cmp timestamp-page page
render
check_urls '\.[a-f0-9]{64}'
cp page hash-page
touch -t 200001010000 cache/*.hash-cache
render hash
cmp hash-page page
for record in cache/*.hash-cache; do
    test "$record" -ot hash-page
done
cp -p first.css original.css
printf 'body { color: blue; }' > first.css
touch -r original.css first.css
cp -p first.js original.js
printf 'var a = 19;' > first.js
touch -r original.js first.js
render
for ext in css js; do
    old=$(grep -Eo "/cache/app\.[a-f0-9]{64}\.$ext" hash-page)
    new=$(grep -Eo "/cache/app\.[a-f0-9]{64}\.$ext" page)
    test "$old" != "$new"
    test -s ".$old"
    digest=$(php -r 'echo hash_file("sha256", $argv[1]);' ".$new")
    test "$new" = "/cache/app.$digest.$ext"
done
render timestamp
cmp timestamp-page page
TEST_DEBUG=1 render timestamp
contains '/first.css?' page
contains '/first.js?' page
TEST_DEBUG=1 render hash
contains '/first.css?' page
TEST_AGE=0 render timestamp
if cmp -s timestamp-page page; then exit 1; fi
for ext in css js; do
    path=$(grep -Eo "/cache/app_[0-9]{14}\.$ext" page)
    test -s ".$path"
done
cp page updated-timestamp
TEST_AGE=0 render timestamp
cmp updated-timestamp page
for ext in css js; do
    path=$(grep -Eo "/cache/app_[0-9]{14}\.$ext" page)
    rm ".$path"
done
render timestamp
cmp updated-timestamp page
for ext in css js; do
    path=$(grep -Eo "/cache/app_[0-9]{14}\.$ext" page)
    test -s ".$path"
done
printf 1700000000 > cache/app.css.cache
render timestamp
contains '/first.css?' page
render timestamp
contains '/cache/app_' page
echo 'PASS: CSS and JS, timestamp and hash, default and override, cache reuse and source edits'

cat > smarty-combine.php <<'PHP'
<?php
return array(
    'public_dir' => __DIR__,
    'manifest' => __DIR__ . '/assets.json',
    'bundles' => array(
        'css' => array('input' => array('/first.css', '/second.css'), 'output' => '/dist/site.css'),
        'js' => array('input' => array('/first.js', '/second.js'), 'output' => '/dist/site.js'),
    ),
);
PHP
php "$suite/../bin/smarty-combine" build
rm first.css second.css first.js second.js
render manifest

# Let the OS choose a free port so parallel runs do not interfere.
address=$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); if (!$s) exit(1); echo stream_socket_get_name($s, false);')
php -S "$address" -t "$DOCUMENT_ROOT" >server.log 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || :; wait "$server_pid" 2>/dev/null || :; rm -rf "$DOCUMENT_ROOT"' EXIT
for attempt in 1 2 3 4 5; do
    if php -r '$s = @stream_socket_client("tcp://" . $argv[1]); exit($s ? 0 : 1);' "$address"; then
        break
    fi
    sleep 1
done
kill -0 "$server_pid" || { cat server.log >&2; exit 1; }
php "$suite/check-manifest.php" "http://$address"

mkdir inspect-templates
cat > inspect-templates/example.tpl <<'TPL'
{* {combine input=$ignored} *}
{combine input=array('/a.js','/b.js')}
{combine
    input=['/a.js', '/b.js']}
{combine input=['/b.js','/a.js']}
{combine input=['/a.js', $dynamic]}
{literal}{combine input=$ignored}{/literal}
{combine bundle='styles'}
TPL
php "$suite/../bin/smarty-combine" inspect inspect-templates --json > inspect.json
php -r '
$r = json_decode(file_get_contents("inspect.json"), true);
if (!$r || array_column($r["calls"], "kind") !== array("static", "static", "static", "review", "bundle")
    || count($r["duplicates"]) !== 1
    || $r["duplicates"][0]["files"] !== array("/a.js", "/b.js")
    || array_column($r["duplicates"][0]["locations"], "line") !== array(2, 3)) {
    fwrite(STDERR, "Unexpected inspect report\n");
    exit(1);
}'
echo 'PASS: inspect reports ordered duplicates and dynamic inputs'

old=dist/site.0000000000000000000000000000000000000000000000000000000000000000.css
printf old > "$old"
touch -t 200001010000 "$old"
printf unrelated > dist/unrelated.css
php "$suite/../bin/smarty-combine" clean --dry-run >clean.log
contains "$old" clean.log
test -f "$old"
php "$suite/../bin/smarty-combine" clean >clean.log
test ! -f "$old"
test -f dist/unrelated.css
php "$suite/check-manifest.php" "http://$address"
echo 'PASS: clean preserves current assets and unrelated files'
php "$suite/inspect-cases.php"
