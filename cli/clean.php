<?php
function combine_clean($args)
{
    $file = 'smarty-combine.php';
    $dry = false;
    $age = 604800;
    foreach ($args as $arg) {
        if ($arg === '--dry-run') {
            $dry = true;
        } elseif (preg_match('/^--keep-seconds=(\d+)$/D', $arg, $m)) {
            $age = (int) $m[1];
        } elseif (substr($arg, 0, 1) !== '-') {
            $file = $arg;
        } else {
            throw new RuntimeException('Usage: smarty-combine clean [config.php] [--dry-run] [--keep-seconds=604800]');
        }
    }
    $config = require $file;
    $root = realpath($config['public_dir']);
    $lock = fopen($config['manifest'] . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Cannot lock manifest');
    }
    try {
        $manifest = json_decode(smarty_combine_read_source($config['manifest']), true);
        if (!$root || !is_array($manifest) || !$manifest) {
            throw new RuntimeException('A valid non-empty manifest and public_dir are required');
        }
        $protected = array();
        foreach ($manifest as $url) {
            if (!is_string($url) || !is_file($root . $url)) {
                throw new RuntimeException('Manifest references a missing or invalid bundle');
            }
            $protected[] = realpath($root . $url);
        }
        $candidates = array();
        foreach ($config['bundles'] as $spec) {
            $output = $spec['output'];
            $dir = realpath(dirname($root . $output));
            if (!$dir || strpos($dir . '/', $root . '/') !== 0) {
                throw new RuntimeException('Bundle output must be inside public_dir');
            }
            $ext = pathinfo($output, PATHINFO_EXTENSION);
            $stem = pathinfo($output, PATHINFO_FILENAME);
            $pattern = '~^' . preg_quote($stem, '~') . '(?:\.[a-f0-9]{64}|_[0-9]{14})\.' . preg_quote($ext, '~') . '$~D';
            // Protect runtime cache targets if the same output is used at runtime.
            foreach (array($root . $output . '.cache', $root . $output . '.hash-cache') as $marker) {
                if (!is_file($marker)) {
                    continue;
                }
                $data = smarty_combine_read_source($marker);
                $record = json_decode($data, true);
                if (is_array($record) && isset($record['filename'])) {
                    $protected[] = realpath($root . $record['filename']);
                } elseif (preg_match('/^[a-f0-9]{64} ([a-f0-9]{64})$/D', $data, $m)) {
                    $protected[] = $dir . '/' . $stem . '.' . $m[1] . '.' . $ext;
                } elseif (ctype_digit(trim($data))) {
                    $protected[] = $dir . '/' . $stem . date('_YmdHis.', filemtime($marker)) . $ext;
                } else {
                    throw new RuntimeException('Invalid runtime cache marker: ' . $marker);
                }
            }
            foreach (new DirectoryIterator($dir) as $entry) {
                if (!$entry->isLink() && $entry->isFile() && preg_match($pattern, $entry->getFilename())
                    && $entry->getMTime() < time() - $age) {
                    $candidates[$entry->getPathname()] = true;
                }
            }
        }
        foreach (array_keys($candidates) as $path) {
            if (in_array($path, $protected, true)) {
                continue;
            }
            if (!$dry && !unlink($path)) {
                throw new RuntimeException('Cannot remove ' . $path);
            }
            echo ($dry ? 'Would remove ' : 'Removed '), $path, "\n";
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
