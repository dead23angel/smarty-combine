<?php
require dirname(__DIR__) . '/cli/inspect.php';
$source = <<<'TPL'
{combine input=['/it\'s.js', "/a.js",]}
{combine input=$items|default:['/fallback.js']}
{combine bundle=$name}
{combine output='/missing.js'}
{combine input=$missing
TPL;
$calls = combine_inspect_template($source, 'case.tpl');
if (count($calls) !== 5 || $calls[0]['files'] !== array("/it's.js", '/a.js')
    || array_column(array_slice($calls, 1), 'reason') !== array(
        'dynamic_or_unsupported_input', 'dynamic_bundle', 'missing_input', 'unterminated_tag')) {
    throw new RuntimeException('Inspect failed to classify complex or incomplete expressions');
}
echo "PASS: inspect handles escapes, trailing commas and review reasons\n";
