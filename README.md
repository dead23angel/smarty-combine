# Smarty Combine

Use `{combine}` to render one stylesheet or script tag for an ordered list of local CSS or JavaScript files. The plugin minifies the files in PHP, rewrites relative CSS `url()` references against the source stylesheet's directory, and adds a semicolon between scripts.

## Compatibility and installation

The package declares PHP **7.4 or newer** and Smarty **2.6.33**, **3.1 through 3.x**, **4.1 through 4.x**, or **5.x**. Your PHP version must also satisfy the requirements of the selected Smarty release. Both versioning modes passed on **Smarty 2.6.33 with PHP 7.4.33**. Integration tests also passed with **Smarty 3.1.46, 4.1.1 and 5.9.0** on PHP 8.5.12. Smarty 3.1.46 and 4.1.1 themselves emit deprecation notices on that PHP version.

To develop against this checkout, place the application and the `smarty-combine` repository in sibling directories. Run these commands from the application directory with the checkout on `master`:

```sh
composer config repositories.smarty-combine '{"type":"path","url":"../smarty-combine","options":{"symlink":true}}'
composer require 'dead23angel/smarty-combine:dev-master'
```

Composer links the checkout into `vendor/`, so edits to the plugin are available to the application immediately.

## Quick start

Place the source files under the public document root and give the PHP process write access to the output directory. For the examples below, set the `DOCUMENT_ROOT` environment variable to the public directory and serve `/cache/` as static files.

In the application bootstrap, load and register the plugin. This example uses Smarty 5. With Smarty 3 or 4, use your existing `Smarty` instance in place of `new \Smarty\Smarty()`.

```php
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/dead23angel/smarty-combine/function.combine.php';

define('SMARTY_COMBINE_VERSION', 'hash');

$smarty = new \Smarty\Smarty();
$smarty->registerPlugin('function', 'combine', static function (array $params, $template) {
    smarty_function_combine($params, $template);
});

$smarty->assign('styles', array('/css/base.css', '/css/theme.css'));
$smarty->assign('scripts', array('/js/core.js', '/js/app.js'));
```

For Smarty 2.6.33, load the same files and register the plugin on your existing instance with the legacy API:

```php
$smarty->register_function('combine', 'smarty_function_combine');
$smarty->assign('styles', array('/css/base.css', '/css/theme.css'));
$smarty->assign('scripts', array('/js/core.js', '/js/app.js'));
```

Call the plugin once per bundle in your template:

```smarty
{combine input=$styles output='/cache/site.css'}
{combine input=$scripts output='/cache/app.js'}
```

## Versioning and cache behaviour

**Hash mode** produces a name such as `site.<sha256>.css`, where the digest covers the final minified bytes. On each render, the plugin reads the sources and checks their paths, contents and order against `site.css.hash-cache` beside the bundle. A match and an existing bundle skip minification. If the record or bundle is missing, the plugin rebuilds on that render. Changing a file's timestamp alone preserves its URL. An edit that produces identical minified bytes also preserves the URL.

Old hashed files remain available to pages that still reference them. Keep them until cached HTML using those URLs has expired. To force a fresh check and minification, remove the corresponding `.hash-cache` file.

**Timestamp mode** is the default when no configuration is set. A cold request emits individual sources and builds the bundle at shutdown. Existing numeric cache markers are migrated the same way. On later requests, `age` controls how often source content is checked (default 3600 seconds). Changed minified contents produce a new timestamp URL even for edits in the same second. An unchanged bundle keeps its URL. Missing bundle files are regenerated immediately. Old bundle files are retained for cached pages.

Set `SMARTY_COMBINE_VERSION` in your application's PHP configuration before rendering to choose a default for all calls. An explicit template option takes precedence in either direction:

```smarty
{combine input=$styles output='/cache/site.css' version='hash'}
{combine input=$scripts output='/cache/app.js' version='timestamp'}
```

## Template parameters

- **`bundle`** — name of a prebuilt CSS or JS bundle in the configured manifest. Use this parameter on its own.
- **`input`** (required for runtime builds) — a non-empty array of local paths in bundle order. All entries in one call must have the same lowercase extension, either `.css` or `.js`.
- **`output`** — base path for the generated file, including its extension. Defaults to `combined.css` or `combined.js` in the first source file's directory.
- **`version`** — `hash` or `timestamp`. Defaults to `SMARTY_COMBINE_VERSION` when defined, otherwise `timestamp`.
- **`use_true_path`** — defaults to `false`, which prefixes filesystem paths with `DOCUMENT_ROOT`. With `true`, paths are used as supplied. The emitted URL still uses the supplied output path, so ensure it maps to a public URL.
- **`debug`** — default `false`. For runtime builds, a truthy value always emits individual source tags with modification-time query strings and performs no bundle writes. Named manifest bundles continue to resolve their manifest URLs.
- **`age`** — interval in seconds between timestamp-mode content checks, default `3600`. Use `0` to check each render. Hash mode checks source contents on each render.
- **`cache_file_name`** — overrides the timestamp marker path, which defaults to `output` plus `.cache`. Hash mode uses its own `.hash-cache` record.

## Running the integration tests

From a package checkout with dependencies installed, run `composer test` (PHP CLI and a POSIX shell). The suite renders actual Smarty templates in separate PHP processes so the timestamp builder can finish at request shutdown. The manifest test removes the original sources and fetches both generated assets over local HTTP. All generated files live in a temporary directory.

To select a series in a disposable test checkout, run `composer update --with 'smarty/smarty:4.1.*'` first. Use `2.6.33` on PHP 7.4 for the Smarty 2 job, or substitute `3.1.*` or `^5` for the other PHP 8.5 jobs. Composer may block historical versions due to published advisories. To reproduce those historical tests only, use an isolated test environment and add `--no-blocking` to that update command (tested with Composer 2.11.2).

## Building bundles during deployment

Create a trusted PHP build configuration named `smarty-combine.php` in the application root:

```php
<?php
return array(
    'public_dir' => __DIR__ . '/public',
    'manifest' => __DIR__ . '/var/assets.json',
    'bundles' => array(
        'styles' => array('input' => array('/css/base.css', '/css/theme.css'), 'output' => '/cache/site.css'),
        'scripts' => array('input' => array('/js/core.js', '/js/app.js'), 'output' => '/cache/app.js'),
    ),
);
```

Run `vendor/bin/smarty-combine build` from that directory, or pass the config path after `build`. Input and output asset paths start at the public root. The command writes bundles with SHA-256 filenames and then atomically replaces the JSON manifest. Deploy the generated bundles before activating the manifest. Keep older hashed files while cached pages can reference them.

In the application bootstrap, define `SMARTY_COMBINE_MANIFEST` as the absolute path to the deployed JSON file. Use the bundle names in templates:

```smarty
{combine bundle='styles'}
{combine bundle='scripts'}
```

Named bundles emit the root-relative URLs recorded in the manifest. Rendering requires read access to the manifest and no source files or writable cache directory. A missing manifest or unknown bundle raises an error. Existing calls that pass `input` continue to use runtime versioning.

## Inspecting existing templates

Run `vendor/bin/smarty-combine inspect templates` to find combine calls recursively in a directory (`.` by default). Add `--json` for a report with `calls` and `duplicates` arrays. Paths are relative to the scanned directory and line numbers start at 1.

The scanner recognizes standard Smarty delimiters. It skips Smarty comments, literal blocks, symlinks and dependency directories. Literal lists written as `array('/a.js', '/b.js')` or `['/a.js', '/b.js']` are grouped by their exact ordered paths. Different output options may still need separate builds. Named bundles are listed separately. Variables and unrecognized input expressions appear as `review` with the source tag for manual inspection. The report describes template source and does not execute PHP or resolve assigned variables.

## Cleaning obsolete bundles

Run `vendor/bin/smarty-combine clean [config.php] --dry-run` to preview removal. Omit `--dry-run` to delete obsolete generated files. The default retention period is seven days. Override it with `--keep-seconds=86400`, for example.

The command uses the same trusted PHP config as `build`. It requires an existing valid manifest and protects its current files plus standard runtime cache targets. It examines only configured output names with SHA-256 or timestamp suffixes. Symlinks and unrelated files are skipped. Build and clean share a manifest lock. Schedule cleanup after deployment, allowing enough retention time for cached HTML. Outputs removed from the config need to be cleaned separately.

Inspect also recognizes escaped quotes/backslashes in literal paths and trailing commas. Review records include a `reason` for dynamic bundle names, unsupported/dynamic inputs, missing input, or an unterminated tag. Variables and filters remain manual-review items.

## Controlling minification

Runtime calls accept a boolean `minify` option:

```smarty
{combine input=$scripts output='/cache/app.js' minify=false}
```

Define `SMARTY_COMBINE_MINIFY` as `false` in the application bootstrap to disable minification by default. An explicit template option overrides it. For deployment builds, set `'minify' => false` at the top level of the build configuration or within a bundle. Bundle settings override the build default. The default remains `true`.

With minification disabled, JavaScript source bytes are preserved with a newline/semicolon separator between files, including files ending in a line comment. CSS keeps its formatting while relative `url()` references are still rebased. Changing the option invalidates the hash cache and triggers a timestamp refresh independently of `age`.

The bundled JSMin is not a complete modern JavaScript parser. Regression fixtures cover optional chaining and nullish coalescing, plus CSS custom properties, string whitespace and `calc()`. JSMin corrupts whitespace inside template literals. Minification therefore rejects any JavaScript containing a backtick with an instruction to use `minify=false`. This conservative check also applies to backticks in comments and ordinary strings. Pre-minified modern JavaScript can be combined with minification disabled.

Build tests cover missing inputs and minification errors after an earlier bundle has been produced, parallel writers using the manifest lock, and deterministic retries. A failed build leaves the previous manifest active. Completed files from a failed attempt may remain on disk and can later be removed with `clean`.
