<?php

if (!function_exists('kasi_exchange_app_base_path')) {
    function kasi_exchange_app_base_path(): string
    {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptDir = rtrim(dirname($scriptName), '/');

        if ($scriptDir === '.' || $scriptDir === '/') {
            return '';
        }

        if (basename($scriptDir) === 'admin') {
            $scriptDir = rtrim(dirname($scriptDir), '/');
        }

        return $scriptDir === '/' ? '' : $scriptDir;
    }
}

if (!function_exists('kasi_exchange_url')) {
    function kasi_exchange_url(string $path = ''): string
    {
        $basePath = kasi_exchange_app_base_path();
        $path = ltrim($path, '/');

        if ($basePath === '') {
            return '/' . $path;
        }

        return $basePath . '/' . $path;
    }
}
