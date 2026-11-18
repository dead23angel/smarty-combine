<?php

/**
 * Smarty plugin
 * @package Smarty
 * @subpackage plugins
 */

/**
 * Smarty combine function plugin
 *
 * Type:    function<br>
 * Name:    combine<br>
 * Date:    September 5, 2015
 * Purpose: Combine content from several JS or CSS files into one
 * Input:   string to count
 * Example: {combine input=$array_of_files_to_combine output=$path_to_output_file use_true_path=true age=$seconds_to_try_recombine_file}
 *
 * @author Gorochov Ivan <dead23angel at gmail dot com>
 * @author Vital Smereka <vds911 at yandex dot com>
 * @version 1.4
 * @param array
 * @param string
 * @param int
 * @return string
 */

function smarty_function_combine($params, &$smarty)
{
    if (isset($params['bundle'])) {
        smarty_combine_render_bundle($params['bundle']);
        return;
    }
    require_once dirname(__FILE__) . '/minify/JSmin.php';
    require_once dirname(__FILE__) . '/minify/CSSmin.php';

    /**
     * Build combined file
     *
     * @param array $params
     */
    if ( ! function_exists('smarty_build_combine')) {
        function smarty_build_combine($params, $skip_out_for_shutdown = false)
        {
            $filelist = array();
            $lastest_mtime = 0;

            foreach ($params['input'] as $item) {
                $mtime = filemtime($params['file_path'] . $item);
                $lastest_mtime = max($lastest_mtime, $mtime);
                $filelist[] = array('name' => $item, 'time' => $mtime);
            }

            if ($params['debug'] === true) {
                $output_filename = '';
                foreach ($filelist as $file) {
                    if ($params['type'] == 'js') {
                        $output_filename .= '<script type="text/javascript" src="' . base_url() . $file['name'] . '?' . $file['time'] . '" charset="utf-8"></script>' . "\n";
                    } elseif ($params['type'] == 'css') {
                        $output_filename .= '<link type="text/css" rel="stylesheet" href="' . base_url() . $file['name'] . '?' . $file['time'] . '" />' . "\n";
                    }
                }

                echo $output_filename;
                return;
            }

            $last_cmtime = 0;

            if (file_exists($params['file_path'] . $params['cache_file_name'])) {
                $last_cmtime = filemtime($params['file_path'] . $params['cache_file_name']);
            }

            if ($lastest_mtime > $last_cmtime) {
                $glob_mask = preg_replace('/\.(js|css)$/i', '_*.$1', $params['output']);
                $files_to_cleanup = glob($params['file_path'] . $glob_mask);

                foreach ($files_to_cleanup as $cfile) {
                    if (is_file($cfile) && file_exists($cfile)) {
                        unlink($cfile);
                    }
                }

                $output_filename = preg_replace('/\.(js|css)$/i', date('_YmdHis.', $lastest_mtime) . '$1', $params['output']);

                $dirname = dirname($params['file_path'] . $output_filename);

                if ( ! is_dir($dirname)) {
                    mkdir($dirname, 0755, true);
                }

				$sleep = checkWritabeFile($params['file_path'] . $output_filename);

                $fh = fopen($params['file_path'] . $output_filename, 'w');

                if (flock($fh, LOCK_EX)) {
                    foreach ($filelist as $file) {
                        $min = smarty_combine_minify_file($params, $file['name']);
                        fputs($fh, $min);
                    }

                    flock($fh, LOCK_UN);
                    file_put_contents($params['file_path'] . $params['cache_file_name'], $lastest_mtime, LOCK_EX);
                }

                fclose($fh);
                clearstatcache();
            }

            touch($params['file_path'] . $params['cache_file_name'], $lastest_mtime);

			if(!$skip_out_for_shutdown){
				smarty_print_out($params);
			}
        }
    }

    /**
     * Print filename
     *
     * @param string $params
     */
    if ( ! function_exists('smarty_print_out')) {
        function smarty_print_out($params)
        {
            $mtime = 0;

            if (file_exists($params['file_path'] . $params['cache_file_name'])) {
				$mtime = filemtime($params['file_path'] . $params['cache_file_name']);
            }

            $output_filename = preg_replace('/\.(js|css)$/i', date('_YmdHis.', $mtime) . '$1', $params['output']);

            smarty_combine_print_tag($params['type'], $output_filename);
        }
    }

    /**
     * This function gets the base url for the project where this plugin is used
     * If this plugin is used within Code Igniter, the base_url() would have already been defined
     */
    if ( ! function_exists('base_url')) {
        function base_url(){

            return sprintf(
                "%s://%s%s",
                isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
                $_SERVER['HTTP_HOST'],
                rtrim(dirname($_SERVER['PHP_SELF']), '/\\')
            );
        }
    }

    // The new 'use_true_path' option that tells this plugin to use the path to the files as it is
    if ( isset($params['use_true_path']) && !is_bool($params['use_true_path'])) {
        trigger_error('use_true_path must be boolean', E_USER_NOTICE);
        return;
    }

    if ( ! isset($params['use_true_path'])) {
        $params['use_true_path'] = false;
    }

    // use the relative path or the true path of the file based on the 'use_true_path' option passed in
    $params['file_path'] = ($params['use_true_path']) ? '' : getenv('DOCUMENT_ROOT');


    if ( ! isset($params['input'])) {
        trigger_error('input cannot be empty', E_USER_NOTICE);
        return;
    }

    if ( ! is_array($params['input']) || count($params['input']) < 1) {
        trigger_error('input must be array and have one item at least', E_USER_NOTICE);
        return;
    }

    foreach ($params['input'] as $file) {
        if ( ! file_exists($params['file_path'] . $file)) {
            trigger_error('File ' . $params['file_path'] . $file . ' does not exist!', E_USER_WARNING);
            return;
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);

        if ( ! in_array($ext, array('js', 'css'))) {
            trigger_error('all input files must have js or css extension', E_USER_NOTICE);
            return;
        }

        $files_extensions[] = $ext;
    }

    if (count(array_unique($files_extensions)) > 1) {
        trigger_error('all input files must have the same extension', E_USER_NOTICE);
        return;
    }

    $params['type'] = $ext;

    if ( ! isset($params['output'])) {
        $params['output'] = dirname($params['input'][0]) . '/combined.' . $ext;
    }

    if ( ! isset($params['age'])) {
        $params['age'] = 3600;
    }

    if ( ! isset($params['cache_file_name'])) {
        $params['cache_file_name'] = $params['output'] . '.cache';
    }

    if ( ! isset($params['debug'])) {
        $params['debug'] = false;
    }

    if ($params['debug']) {
        foreach ($params['input'] as $name) {
            smarty_combine_print_tag($params['type'], $name . '?' . filemtime($params['file_path'] . $name));
        }
        return;
    }
    $version = isset($params['version']) ? $params['version']
        : (defined('SMARTY_COMBINE_VERSION') ? SMARTY_COMBINE_VERSION : 'timestamp');
    if ($version === 'hash') {
        smarty_combine_print_tag($params['type'], smarty_combine_hash_bundle($params));
        return;
    }
    $marker = $params['file_path'] . $params['cache_file_name'];
    $record = is_file($marker) ? json_decode(smarty_combine_read_source($marker), true) : null;
    if (is_array($record) && isset($record['filename'], $record['checked'], $record['digest'])) {
        if (!is_file($params['file_path'] . $record['filename'])
            || time() - $record['checked'] >= max(0, (int) $params['age'])) {
            $record = smarty_combine_timestamp($params);
        }
        smarty_combine_print_tag($params['type'], $record['filename']);
        return;
    }
    // Cold caches and legacy numeric markers are rebuilt after the response.
    foreach ($params['input'] as $name) {
        smarty_combine_print_tag($params['type'], $name . '?' . filemtime($params['file_path'] . $name));
    }
    register_shutdown_function('smarty_combine_timestamp', $params);
    return;
}

function smarty_combine_timestamp($params)
{
    $marker = $params['file_path'] . $params['cache_file_name'];
    $dir = dirname($marker);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create cache directory');
    }
    $lock = fopen($marker . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Cannot lock timestamp cache');
    }
    try {
        $old = is_file($marker) ? json_decode(smarty_combine_read_source($marker), true) : null;
        $bytes = '';
        foreach ($params['input'] as $name) {
            $bytes .= smarty_combine_minify_file($params, $name);
        }
        $digest = hash('sha256', $bytes);
        if (is_array($old) && isset($old['digest'], $old['filename'], $old['stamp'])
            && $old['digest'] === $digest) {
            $filename = $old['filename'];
            $stamp = $old['stamp'];
        } else {
            $stamp = max(time(), is_array($old) && isset($old['stamp']) ? $old['stamp'] + 1 : 0);
            $filename = preg_replace('/\.(js|css)$/iD', date('_YmdHis.', $stamp) . '$1', $params['output'], 1, $count);
            if (!$count) {
                throw new RuntimeException('Timestamp versioning requires a .css or .js output filename');
            }
        }
        if (is_file($params['file_path'] . $filename)
            && hash_file('sha256', $params['file_path'] . $filename) !== $digest) {
            do {
                ++$stamp;
                $filename = preg_replace('/\.(js|css)$/iD', date('_YmdHis.', $stamp) . '$1', $params['output']);
            } while (is_file($params['file_path'] . $filename));
        }
        if (!is_file($params['file_path'] . $filename)) {
            smarty_combine_write_atomic($params['file_path'] . $filename, $bytes);
        }
        $record = array('filename' => $filename, 'digest' => $digest, 'checked' => time(), 'stamp' => $stamp);
        smarty_combine_write_atomic($marker, json_encode($record));
        return $record;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function smarty_combine_hash_bundle($params)
{
    if (!preg_match('/\.(js|css)$/iD', $params['output'])) {
        throw new RuntimeException('Hash versioning requires a .css or .js output filename');
    }
    // Increment the format version when minification or URL rewriting changes.
    $context = hash_init('sha256');
    hash_update($context, serialize(array(1, $params['type'], $params['file_path'], $_SERVER['DOCUMENT_ROOT'])));
    $sources = array();
    foreach ($params['input'] as $name) {
        $source = smarty_combine_read_source($params['file_path'] . $name);
        hash_update($context, serialize(array($name, hash('sha256', $source))));
        $sources[] = array($name, $source);
    }
    $key = hash_final($context);
    $manifest = $params['file_path'] . $params['output'] . '.hash-cache';
    $record = is_file($manifest) ? @file_get_contents($manifest) : false;
    if ($record !== false && preg_match('/^' . $key . ' ([a-f0-9]{64})$/D', $record, $match)) {
        $filename = preg_replace('/\.(js|css)$/iD', '.' . $match[1] . '.$1', $params['output']);
        if (is_file($params['file_path'] . $filename)) {
            return $filename;
        }
    }

    $bundle = '';
    foreach ($sources as $item) {
        $bundle .= smarty_combine_minify_file($params, $item[0], $item[1]);
    }
    $digest = hash('sha256', $bundle);
    $filename = smarty_combine_publish_bundle($params['file_path'], $params['output'], $bundle, $digest);
    smarty_combine_write_atomic($manifest, $key . ' ' . $digest);
    return $filename;
}

function smarty_combine_publish_bundle($root, $output, $bundle, $digest)
{
    $filename = preg_replace('/\.(js|css)$/iD', '.' . $digest . '.$1', $output);
    $target = $root . $filename;
    if (!is_file($target)) {
        smarty_combine_write_atomic($target, $bundle);
    }
    return $filename;
}

function smarty_combine_render_bundle($name)
{
    if (!defined('SMARTY_COMBINE_MANIFEST')) {
        throw new RuntimeException('Set SMARTY_COMBINE_MANIFEST to the built manifest path');
    }
    static $entries;
    if ($entries === null) {
        $entries = json_decode(smarty_combine_read_source(SMARTY_COMBINE_MANIFEST), true);
        if (!is_array($entries)) {
            throw new RuntimeException('Invalid Smarty Combine manifest');
        }
    }
    if (!is_string($name) || !isset($entries[$name]) || !is_string($entries[$name])) {
        throw new RuntimeException('Unknown Smarty Combine bundle: ' . (is_string($name) ? $name : '(invalid name)'));
    }
    $path = $entries[$name];
    $type = pathinfo($path, PATHINFO_EXTENSION);
    $url = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
    if ($type === 'css') {
        echo '<link rel="stylesheet" href="' . $url . '" />';
    } elseif ($type === 'js') {
        echo '<script src="' . $url . '"></script>';
    } else {
        throw new RuntimeException('Invalid bundle extension for ' . $name);
    }
}

function smarty_combine_write_atomic($target, $bytes)
{
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create bundle directory');
    }
    $tmp = tempnam($dir, '.combine-');
    if ($tmp === false) {
        throw new RuntimeException('Cannot create temporary bundle');
    }
    try {
        if (file_put_contents($tmp, $bytes) !== strlen($bytes)
            || !chmod($tmp, 0666 & ~umask()) || !rename($tmp, $target)) {
            throw new RuntimeException('Cannot publish bundle');
        }
    } finally {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }
}

function smarty_combine_print_tag($type, $output_filename)
{
    if ($type == 'js') {
        echo '<script type="text/javascript" src="' . base_url() . $output_filename . '" charset="utf-8"></script>';
    } elseif ($type == 'css') {
        echo '<link type="text/css" rel="stylesheet" href="' . base_url() . $output_filename . '" />';
    } else {
        echo $output_filename;
    }
}

function smarty_combine_read_source($path)
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Cannot read asset ' . $path);
    }
    return $source;
}

function smarty_combine_minify_file($params, $name, $source = null)
{
    $path = $params['file_path'] . $name;
    if ($source === null) {
        $source = smarty_combine_read_source($path);
    }
    if ($params['type'] === 'js') {
        return JSMin::minify($source) . ';';
    }
    $dirname = dirname(str_replace($_SERVER['DOCUMENT_ROOT'], '', $path));
    return CSSMin::minify(preg_replace('/url\\(((?>["\']?))(?!(\\/|http(s)?:|data:|#))(.*?)\\1\\)/', 'url("' . $dirname . '/$4")', $source));
}

function checkWritabeFile($file)
{
	$i = 0;

	while(!is_writable($file))
	{
		$i++;

		if($i > 5)
		{
			break;
		}

		sleep(rand(0,2));
	}

	return $i;
}
