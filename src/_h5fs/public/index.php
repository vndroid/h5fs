<?php

define('H5FS_VERSION', '{{VERSION}}');
define('MIN_PHP_VERSION', '8.0.0');

if (version_compare(PHP_VERSION, MIN_PHP_VERSION, '<')) {
    header('Content-type: text/plain;charset=utf-8');
    exit('[ERR] h5fs requires PHP ' . MIN_PHP_VERSION . ' or later, but found PHP ' . PHP_VERSION);
}

if (str_starts_with(H5FS_VERSION, '{')) {
    header('Content-type: text/plain;charset=utf-8');
    exit('[ERR] h5fs sources must be preprocessed to work correctly');
}

require_once __DIR__ . '/../private/php/class-bootstrap.php';
Bootstrap::run();
