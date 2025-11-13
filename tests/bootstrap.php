<?php
if (!defined('DOL_DOCUMENT_ROOT')) {
    define('DOL_DOCUMENT_ROOT', __DIR__ . '/stubs');
}

if (!defined('MAIN_DB_PREFIX')) {
    define('MAIN_DB_PREFIX', 'llx_');
}

if (!defined('LOG_ERR')) {
    define('LOG_ERR', 3);
}

if (!defined('LOG_DEBUG')) {
    define('LOG_DEBUG', 7);
}

if (!defined('LOG_WARNING')) {
    define('LOG_WARNING', 4);
}

if (!function_exists('dol_now')) {
    function dol_now()
    {
        return time();
    }
}

if (!function_exists('dol_syslog')) {
    function dol_syslog($message, $level = 0)
    {
        // no-op for tests
    }
}

if (!function_exists('getEntity')) {
    function getEntity($element)
    {
        return '1';
    }
}

if (!class_exists('Conf')) {
    class Conf
    {
        public $entity = 1;
        public $global;

        public function __construct()
        {
            $this->global = new stdClass();
        }
    }
}

require_once __DIR__ . '/../lib/fvfiscal_import_service.class.php';
