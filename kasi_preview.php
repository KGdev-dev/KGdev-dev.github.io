<?php
$_SERVER['SCRIPT_NAME'] = '/kasi_exchange/index.php';
$_SERVER['REQUEST_URI'] = '/kasi_exchange/index.php';
ob_start();
include __DIR__ . '/index.php';
$html = ob_get_clean();
$html = str_replace('/kasi_exchange/', './', $html);
file_put_contents(__DIR__ . '/preview.html', $html);
