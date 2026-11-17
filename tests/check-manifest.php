<?php
$html = file_get_contents('page');
$manifest = json_decode(file_get_contents('assets.json'), true);
foreach (array('css' => '<link[^>]+href', 'js' => '<script[^>]+src') as $type => $tag) {
    if (!preg_match('~' . $tag . '="(/dist/site\.([a-f0-9]{64})\.' . $type . ')"~', $html, $match)
        || $manifest[$type] !== $match[1]) {
        throw new RuntimeException('Expected the manifest URL in the rendered ' . $type . ' tag');
    }
    $bytes = file_get_contents($argv[1] . $match[1]);
    if ($bytes === false || hash('sha256', $bytes) !== $match[2]) {
        throw new RuntimeException('Cannot fetch the expected ' . $type . ' bundle');
    }
    $markers = $type === 'css' ? array('blue', 'navy') : array('19', '23');
    foreach ($markers as $marker) {
        if (strpos($bytes, $marker) === false) {
            throw new RuntimeException('Missing source content in ' . $match[1]);
        }
    }
}
echo "PASS: build and manifest URLs serve CSS and JS after source removal\n";
