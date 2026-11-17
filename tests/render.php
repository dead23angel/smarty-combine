<?php
// Each invocation simulates one request, including PHP shutdown handlers.
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/function.combine.php';
$root = getenv('DOCUMENT_ROOT');
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['HTTP_HOST'] = 'test.local';
$_SERVER['PHP_SELF'] = '/index.php';
$smarty = class_exists('Smarty\\Smarty') ? new \Smarty\Smarty() : new Smarty();
$legacy = isset($smarty->_version) && $smarty->_version === '2.6.33';
if ($legacy) {
    $smarty->compile_dir = $root . '/compiled';
    $smarty->template_dir = $root;
    $smarty->register_function('combine', 'smarty_function_combine');
} else {
    $smarty->setCompileDir($root . '/compiled');
    $smarty->registerPlugin('function', 'combine', static function ($params, $template) {
        smarty_function_combine($params, $template);
    });
}
if (getenv('TEST_VERSION')) {
    define('SMARTY_COMBINE_VERSION', getenv('TEST_VERSION'));
}
$manifestMode = isset($argv[1]) && $argv[1] === 'manifest';
if ($manifestMode) {
    define('SMARTY_COMBINE_MANIFEST', $root . '/assets.json');
}
foreach (array('css', 'js') as $ext) {
    if ($manifestMode) {
        $source = "{combine bundle='" . $ext . "'}";
    } else {
        $smarty->assign('files', array('/first.' . $ext, '/second.' . $ext));
        $version = isset($argv[1]) ? " version='" . $argv[1] . "'" : '';
        $source = "{combine input=\$files output='/cache/app." . $ext . "'" . $version . "}";
    }
    if ($legacy) {
        $file = $ext . '-' . (isset($argv[1]) ? $argv[1] : 'default') . '.tpl';
        file_put_contents($root . '/' . $file, $source);
        echo $smarty->fetch($file);
    } else {
        echo $smarty->fetch('string:' . $source);
    }
}
