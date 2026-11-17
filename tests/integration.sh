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
echo 'PASS: CSS and JS, timestamp and hash, default and override, cache reuse and source edits'
