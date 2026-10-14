<?php
// Filesystem root and base URL of the app.

// -- Paths ------------------------------------------------------
if (!defined('APP_ROOT')) define('APP_ROOT', dirname(__DIR__, 2));

/**
 * Web path of the project root (e.g. "/SUCFRMS/"), derived from the running
 * script so it works for entry points at any depth (index.php, frontend/pages/*,
 * backend/api/*, backend/services/reports/*) and under any Apache alias.
 */
if (!defined('BASE_URL')) {
    define('BASE_URL', (function (): string {
        $script = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $root   = str_replace('\\', '/', (string)realpath(APP_ROOT));
        $name   = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $rel    = ($root !== '' && str_starts_with($script, $root)) ? substr($script, strlen($root)) : '';
        if ($rel === '' || !str_ends_with(strtolower($name), strtolower($rel))) return '/';
        return rtrim(substr($name, 0, -strlen($rel)), '/') . '/';
    })());
}
