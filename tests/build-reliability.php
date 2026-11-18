<?php
require dirname(__DIR__) . '/function.combine.php';
require dirname(__DIR__) . '/minify/JSmin.php';
require dirname(__DIR__) . '/minify/CSSmin.php';
function check($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
}
$root = getcwd();
$_SERVER['DOCUMENT_ROOT'] = $root;
$js = 'const user = {name: "Ada"}; console.log(user?.name ?? "guest");';
$literal = 'const label = `hello   world`; // trailing comment';
$css = ':root { --gap: 10px; --label: "a  b"; } .x { width: calc(100% - var(--gap)); background: url(../img/a.png); }';
file_put_contents('modern.js', $js);
file_put_contents('literal.js', $literal);
file_put_contents('modern.css', $css);
$params = array('file_path' => $root, 'type' => 'js', 'minify' => false);
check(smarty_combine_minify_file($params, '/literal.js') === $literal . "\n;\n", 'Literal changed');
$params['minify'] = true;
$min = smarty_combine_minify_file($params, '/modern.js');
check(strpos($min, 'user?.name??"guest"') !== false, 'Optional chaining or nullish coalescing changed');
try {
    smarty_combine_minify_file($params, '/literal.js');
    throw new LogicException('Template literal was silently minified');
} catch (RuntimeException $e) {
    check(strpos($e->getMessage(), 'minify=false') !== false, 'Missing actionable error');
}
$params['type'] = 'css';
$min = smarty_combine_minify_file($params, '/modern.css');
check(strpos($min, 'calc(100% - var(--gap))') !== false && strpos($min, '--label:"a  b"') !== false, 'CSS semantics changed');
$params['minify'] = false;
$raw = smarty_combine_minify_file($params, '/modern.css');
check(strpos($raw, ':root { --gap: 10px;') === 0 && strpos($raw, 'url("/../img/a.png")') !== false, 'CSS passthrough or URL rebasing failed');

$config = array('public_dir' => $root, 'manifest' => $root . '/reliable.json', 'minify' => false,
    'bundles' => array(
        'js' => array('input' => array('/literal.js', '/modern.js'), 'output' => '/reliable/app.js'),
        'css' => array('input' => array('/modern.css'), 'output' => '/reliable/app.css', 'minify' => true)));
function config_file($name, $config) {
    file_put_contents($name, "<?php\nreturn " . var_export($config, true) . ";\n");
}
function start_build($file) {
    $p = proc_open(array(PHP_BINARY, dirname(__DIR__) . '/bin/smarty-combine', 'build', $file),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $file . '.log', 'a'), 2 => array('file', $file . '.log', 'a')), $pipes);
    check(is_resource($p), 'Cannot start build');
    return $p;
}
function valid_manifest() {
    $m = json_decode(file_get_contents('reliable.json'), true);
    check(is_array($m) && count($m) === 2, 'Partial or invalid manifest');
    foreach ($m as $path) {
        check(is_file(getcwd() . $path), 'Manifest points to missing asset');
        check(strpos($path, hash_file('sha256', getcwd() . $path)) !== false, 'Asset hash mismatch');
    }
}
config_file('good.php', $config);
check(proc_close(start_build('good.php')) === 0, 'Initial build failed');
valid_manifest();
$before = file_get_contents('reliable.json');
$bad = $config;
$bad['bundles']['css']['input'] = array('/missing.css');
config_file('bad.php', $bad);
// Force the first bundle to change before the second fails.
file_put_contents('modern.js', $js . "\nconsole.log(2);");
check(proc_close(start_build('bad.php')) !== 0, 'Missing input should fail');
check(file_get_contents('reliable.json') === $before, 'Failure replaced live manifest');
valid_manifest();
$bad['bundles']['css']['input'] = array('/literal.js');
$bad['bundles']['css']['output'] = '/reliable/fail.js';
config_file('bad.php', $bad);
check(proc_close(start_build('bad.php')) !== 0, 'Unsafe minification should fail');
check(file_get_contents('reliable.json') === $before, 'Minifier failure replaced manifest');

// Hold the shared lock until both writers have been launched.
$lock = fopen('reliable.json.lock', 'c');
flock($lock, LOCK_EX);
$a = start_build('good.php');
$b = start_build('good.php');
check(file_get_contents('reliable.json') === $before, 'Writer bypassed lock');
flock($lock, LOCK_UN);
fclose($lock);
check(proc_close($a) === 0 && proc_close($b) === 0, 'Concurrent builds failed');
valid_manifest();
$after = file_get_contents('reliable.json');
check($after !== $before, 'Changed source did not update manifest');
check(proc_close(start_build('good.php')) === 0 && file_get_contents('reliable.json') === $after, 'Retry is not deterministic');
check(glob('reliable/.combine-*') === array(), 'Temporary files leaked');
echo "PASS: modern syntax, minify opt-out, failed builds, parallel builds and retries\n";
