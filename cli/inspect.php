<?php

// Scan default-delimiter Smarty syntax without compiling or executing templates.
function combine_inspect_attributes($text)
{
    $attributes = array();
    $offset = 0;
    while (preg_match('/\G\s*([\w-]+)\s*=\s*/', $text, $m, 0, $offset)) {
        $start = $offset += strlen($m[0]);
        $quote = '';
        $depth = 0;
        for ($n = strlen($text); $offset < $n; ++$offset) {
            $c = $text[$offset];
            if ($quote !== '') {
                if ($c === '\\') {
                    ++$offset;
                } elseif ($c === $quote) {
                    $quote = '';
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif (strpos('([{', $c) !== false) {
                ++$depth;
            } elseif (strpos(')]}', $c) !== false) {
                --$depth;
            } elseif (!$depth && ctype_space($c) && preg_match('/\G\s+[\w-]+\s*=/', $text, $next, 0, $offset)) {
                break;
            }
        }
        $attributes[strtolower($m[1])] = trim(substr($text, $start, $offset - $start));
    }
    return $attributes;
}

function combine_inspect_string($expr)
{
    if (strlen($expr) < 2 || ($expr[0] !== "'" && $expr[0] !== '"') || substr($expr, -1) !== $expr[0]) {
        return null;
    }
    $quote = $expr[0];
    $value = '';
    for ($i = 1; $i < strlen($expr) - 1; ++$i) {
        $c = $expr[$i];
        if ($c === $quote || ($quote === '"' && strpbrk($c, '$`{}') !== false)) {
            return null;
        }
        if ($c === '\\') {
            $next = $expr[++$i];
            if ($next !== $quote && $next !== '\\') {
                return null;
            }
            $c = $next;
        }
        $value .= $c;
    }
    return $value;
}

function combine_inspect_files($expr)
{
    if (!preg_match('/^(?:array\s*\((.*)\)|\[(.*)\])$/isD', $expr, $m)) {
        return null;
    }
    $items = isset($m[2]) ? $m[2] : $m[1];
    $offset = 0;
    $files = array();
    $literal = '(?:\x27(?:\\\\.|[^\x27\\\\])*\x27|"(?:\\\\.|[^"\\\\])*")';
    while (preg_match('~\G\s*(' . $literal . ')\s*(?:,|$)~s', $items, $m, 0, $offset)) {
        $path = combine_inspect_string($m[1]);
        if ($path === null) {
            return null;
        }
        $files[] = $path;
        $offset += strlen($m[0]);
    }
    return $files && trim(substr($items, $offset)) === '' ? $files : null;
}

function combine_inspect_template($source, $file)
{
    $calls = array();
    $offset = 0;
    $line = 1;
    // Match whole comments and literal blocks before considering tags inside them.
    $pattern = '~\{\*.*?(?:\*\}|\z)|\{literal\}.*?(?:\{/literal\}|\z)|\{combine(?=[\s}])((?:[^{}\x22\x27]++|\x22(?:\\\\.|[^\x22\\\\])*\x22|\x27(?:\\\\.|[^\x27\\\\])*\x27|(\{(?:[^{}]++|(?2))*\}))*)(?:\}|\z)~is';
    while (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE, $offset)) {
        list($tag, $pos) = $match[0];
        $line += substr_count(substr($source, $offset, $pos - $offset), "\n");
        if (isset($match[1])) {
            $attrs = combine_inspect_attributes($match[1][0]);
            $call = array('file' => $file, 'line' => $line);
            if (substr($tag, -1) !== '}') {
                $call += array('kind' => 'review', 'expression' => $tag, 'reason' => 'unterminated_tag');
            } elseif (isset($attrs['bundle']) && ($name = combine_inspect_string($attrs['bundle'])) !== null) {
                $call += array('kind' => 'bundle', 'bundle' => $name);
            } elseif (!isset($attrs['bundle']) && isset($attrs['input']) && ($files = combine_inspect_files($attrs['input'])) !== null) {
                $call += array('kind' => 'static', 'files' => $files);
            } else {
                $call += array('kind' => 'review', 'expression' => $tag, 'reason' => isset($attrs['bundle']) ? 'dynamic_bundle' : (isset($attrs['input']) ? 'dynamic_or_unsupported_input' : 'missing_input'));
            }
            $calls[] = $call;
        }
        $offset = $pos + strlen($tag);
        $line += substr_count($tag, "\n");
    }
    if (preg_last_error() !== PREG_NO_ERROR) {
        throw new RuntimeException('Cannot scan ' . $file);
    }
    return $calls;
}

function combine_inspect($args)
{
    $json = false;
    $dir = null;
    foreach ($args as $arg) {
        if ($arg === '--json') {
            $json = true;
        } elseif ($dir === null && substr($arg, 0, 1) !== '-') {
            $dir = $arg;
        } else {
            throw new RuntimeException('Usage: smarty-combine inspect [directory] [--json]');
        }
    }
    $root = realpath($dir === null ? '.' : $dir);
    if (!$root || !is_dir($root)) {
        throw new RuntimeException('Template directory not found');
    }
    $tree = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), function ($item) {
        return !$item->isLink() && !($item->isDir() && in_array($item->getFilename(), array('.git', 'vendor', 'node_modules'), true));
    });
    $paths = array();
    foreach (new RecursiveIteratorIterator($tree) as $item) {
        if ($item->isFile() && strtolower($item->getExtension()) === 'tpl') {
            $paths[] = $item->getPathname();
        }
    }
    sort($paths, SORT_STRING);
    $calls = $groups = array();
    foreach ($paths as $path) {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read ' . $path);
        }
        foreach (combine_inspect_template($contents, substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1)) as $call) {
            $calls[] = $call;
            if ($call['kind'] === 'static') {
                $key = serialize($call['files']);
                $groups[$key]['files'] = $call['files'];
                $groups[$key]['locations'][] = array('file' => $call['file'], 'line' => $call['line']);
            }
        }
    }
    $duplicates = array_values(array_filter($groups, function ($group) { return count($group['locations']) > 1; }));
    if ($json) {
        $out = json_encode(array('calls' => $calls, 'duplicates' => $duplicates), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($out === false) {
            throw new RuntimeException('Cannot encode report as JSON');
        }
        echo $out, "\n";
        return;
    }
    echo count($calls), " calls, ", count($duplicates), " repeated file lists\n";
    foreach ($calls as $call) {
        $detail = isset($call['files']) ? implode(', ', $call['files']) : (isset($call['bundle']) ? $call['bundle'] : $call['expression']);
        echo $call['file'], ':', $call['line'], ' [', $call['kind'], '] ', $detail, "\n";
    }
    foreach ($duplicates as $group) {
        echo 'Repeated: ', implode(', ', $group['files']), ' at ';
        echo implode(', ', array_map(function ($loc) { return $loc['file'] . ':' . $loc['line']; }, $group['locations'])), "\n";
    }
}
