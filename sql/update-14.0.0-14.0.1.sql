<?php
/* Copyright (C) 2025
 * This file is part of FvFiscal module for Dolibarr ERP/CRM.
 */

if (!defined('NOREQUIREUSER')) {
    define('NOREQUIREUSER', '1');
}
if (!defined('NOREQUIREDB')) {
    define('NOREQUIREDB', '1');
}
if (!defined('NOREQUIRESOC')) {
    define('NOREQUIRESOC', '1');
}
if (!defined('NOREQUIRETRAN')) {
    define('NOREQUIRETRAN', '1');
}
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
    define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}
if (!defined('NOLOGIN')) {
    define('NOLOGIN', '1');
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', '1');
}

$included = false;
$paths = array(
    dirname(__DIR__, 3) . '/master.inc.php',
    dirname(__DIR__, 4) . '/master.inc.php',
    dirname(__DIR__, 2) . '/master.inc.php',
);
foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $included = true;
        break;
    }
}

if (!$included || empty($db)) {
    throw new RuntimeException('Dolibarr environment not available to run update script.');
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

$error = 0;

$db->begin();

dolibarr_setconstant($db, 'FVFISCAL_VERSION', '14.0.1', 'chaine', 0, '', $conf->entity);

/**
 * Check if a column exists on the target table.
 */
function fv_column_exists(DoliDB $db, $table, $column)
{
    $sql = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . $table . " LIKE '" . $db->escape($column) . "'";
    $resql = $db->query($sql);
    if (!$resql) {
        throw new RuntimeException($db->lasterror());
    }
    $exists = (bool) $db->num_rows($resql);
    $db->free($resql);

    return $exists;
}

/**
 * Fetch column metadata for comparison purposes.
 */
function fv_column_info(DoliDB $db, $table, $column)
{
    $sql = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . $table . " LIKE '" . $db->escape($column) . "'";
    $resql = $db->query($sql);
    if (!$resql) {
        throw new RuntimeException($db->lasterror());
    }
    $info = $db->fetch_object($resql);
    $db->free($resql);

    return $info ?: null;
}

/**
 * Compare the current column type with the expected definition.
 */
function fv_column_type_matches($definition, $currentType)
{
    $expectedToken = strtoupper(strtok($definition, ' '));
    $currentType = strtoupper($currentType);

    $expectedBase = preg_replace('/\(.*/', '', $expectedToken);
    $currentBase = preg_replace('/\(.*/', '', $currentType);

    $normalize = function ($type) {
        switch ($type) {
            case 'INT':
            case 'INTEGER':
                return 'INTEGER';
            case 'SMALLINT':
                return 'SMALLINT';
            case 'TINYINT':
                return 'TINYINT';
            case 'BIGINT':
                return 'BIGINT';
            case 'MEDIUMTEXT':
                return 'MEDIUMTEXT';
            case 'TEXT':
                return 'TEXT';
            case 'MEDIUMBLOB':
                return 'MEDIUMBLOB';
            case 'BLOB':
                return 'BLOB';
            case 'DATETIME':
                return 'DATETIME';
            case 'DATE':
                return 'DATE';
            case 'TIME':
                return 'TIME';
            case 'VARCHAR':
                return 'VARCHAR';
            case 'CHAR':
                return 'CHAR';
            case 'NUMERIC':
            case 'DECIMAL':
                return 'DECIMAL';
            default:
                return $type;
        }
    };

    $expectedNormalized = $normalize($expectedBase);
    $currentNormalized = $normalize($currentBase);

    if ($expectedNormalized !== $currentNormalized) {
        return false;
    }

    if (in_array($expectedNormalized, array('VARCHAR', 'CHAR', 'DECIMAL'), true)) {
        $expectedLength = null;
        if (preg_match('/\(([^)]+)\)/', $expectedToken, $match)) {
            $expectedLength = $match[1];
        }
        $currentLength = null;
        if (preg_match('/\(([^)]+)\)/', $currentType, $match)) {
            $currentLength = $match[1];
        }
        if ($expectedLength !== $currentLength) {
            return false;
        }
    }

    return true;
}

/**
 * Ensure a column is present with the provided definition.
 */
function fv_ensure_column(DoliDB $db, $table, $column, $definition, &$error)
{
    try {
        $exists = fv_column_exists($db, $table, $column);
    } catch (RuntimeException $exception) {
        print 'Error during schema introspection: ' . $exception->getMessage() . "\n";
        $error++;
        return;
    }

    $alterSql = '';
    if (!$exists) {
        $alterSql = "ALTER TABLE " . MAIN_DB_PREFIX . $table . " ADD COLUMN " . $column . ' ' . $definition;
    } else {
        try {
            $info = fv_column_info($db, $table, $column);
        } catch (RuntimeException $exception) {
            print 'Error during schema introspection: ' . $exception->getMessage() . "\n";
            $error++;
            return;
        }

        if ($info) {
            if (!fv_column_type_matches($definition, $info->Type)) {
                $alterSql = "ALTER TABLE " . MAIN_DB_PREFIX . $table . " MODIFY COLUMN " . $column . ' ' . $definition;
            } else {
                $nullableExpected = (stripos($definition, 'NOT NULL') === false);
                $nullableCurrent = ($info->Null === 'YES');
                if ($nullableExpected !== $nullableCurrent) {
                    $alterSql = "ALTER TABLE " . MAIN_DB_PREFIX . $table . " MODIFY COLUMN " . $column . ' ' . $definition;
                } else {
                    $defaultExpected = null;
                    $hasDefaultExpected = false;
                    if (preg_match('/DEFAULT\s+([^\s]+)/i', $definition, $matches)) {
                        $defaultExpected = trim($matches[1], "'\"");
                        $hasDefaultExpected = true;
                    }

                    $defaultCurrent = $info->Default;
                    $hasDefaultCurrent = ($defaultCurrent !== null);

                    if ($hasDefaultExpected !== $hasDefaultCurrent) {
                        $alterSql = "ALTER TABLE " . MAIN_DB_PREFIX . $table . " MODIFY COLUMN " . $column . ' ' . $definition;
                    } elseif ($hasDefaultExpected && strcasecmp($defaultExpected, (string) $defaultCurrent) !== 0) {
                        $alterSql = "ALTER TABLE " . MAIN_DB_PREFIX . $table . " MODIFY COLUMN " . $column . ' ' . $definition;
                    }
                }
            }
        }
    }

    if ($alterSql !== '') {
        dolibarr_ifsql($alterSql, $error);
    }
}

/**
 * Check if an index exists on the table.
 */
function fv_index_exists(DoliDB $db, $table, $indexName)
{
    $sql = "SHOW INDEX FROM " . MAIN_DB_PREFIX . $table . " WHERE Key_name='" . $db->escape($indexName) . "'";
    $resql = $db->query($sql);
    if (!$resql) {
        throw new RuntimeException($db->lasterror());
    }
    $exists = (bool) $db->num_rows($resql);
    $db->free($resql);

    return $exists;
}

/**
 * Ensure that an index or unique constraint exists.
 */
function fv_ensure_index(DoliDB $db, $table, $indexName, $expression, &$error)
{
    try {
        $exists = fv_index_exists($db, $table, $indexName);
    } catch (RuntimeException $exception) {
        print 'Error while inspecting indexes: ' . $exception->getMessage() . "\n";
        $error++;
        return;
    }

    if (!$exists) {
        $sql = "ALTER TABLE " . MAIN_DB_PREFIX . $table . ' ADD ' . $expression;
        dolibarr_ifsql($sql, $error);
    }
}

// Ensure partner profile schema
fv_ensure_column($db, 'fv_partner_profile', 'entity', 'INTEGER NOT NULL DEFAULT 1', $error);
fv_ensure_column($db, 'fv_partner_profile', 'status', 'SMALLINT NOT NULL DEFAULT 1', $error);
fv_ensure_column($db, 'fv_partner_profile', 'ref', 'VARCHAR(128) NOT NULL', $error);
fv_ensure_column($db, 'fv_partner_profile', 'fk_soc', 'INTEGER', $error);
fv_ensure_column($db, 'fv_partner_profile', 'settings_json', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_partner_profile', 'remote_id', 'VARCHAR(64)', $error);
fv_ensure_column($db, 'fv_partner_profile', 'remote_sync_date', 'DATETIME', $error);
fv_ensure_column($db, 'fv_partner_profile', 'created_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_partner_profile', 'updated_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_partner_profile', 'fk_user_create', 'INTEGER', $error);
fv_ensure_column($db, 'fv_partner_profile', 'fk_user_modif', 'INTEGER', $error);

fv_ensure_index($db, 'fv_partner_profile', 'uk_fv_partner_profile_ref', 'UNIQUE KEY uk_fv_partner_profile_ref (entity, ref)', $error);
fv_ensure_index($db, 'fv_partner_profile', 'idx_fv_partner_profile_soc', 'INDEX idx_fv_partner_profile_soc (fk_soc)', $error);
fv_ensure_index($db, 'fv_partner_profile', 'idx_fv_partner_profile_remote', 'INDEX idx_fv_partner_profile_remote (remote_id)', $error);

// Ensure SEFAZ profile schema
fv_ensure_column($db, 'fv_sefaz_profile', 'entity', 'INTEGER NOT NULL DEFAULT 1', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'status', 'SMALLINT NOT NULL DEFAULT 0', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'ref', 'VARCHAR(128) NOT NULL', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'name', 'VARCHAR(255) NOT NULL', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'environment', "VARCHAR(32) NOT NULL DEFAULT 'production'", $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'email', 'VARCHAR(255)', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'certificate_path', 'VARCHAR(255)', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'certificate_password', 'VARCHAR(128)', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'certificate_expire_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'tax_regime', 'VARCHAR(64)', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'tax_regime_detail', 'VARCHAR(64)', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'csc_id', 'VARCHAR(32)', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'csc_token', 'VARCHAR(80)', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'webhook_secret', 'VARCHAR(80)', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'note_public', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'note_private', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'created_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'updated_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'fk_user_create', 'INTEGER', $error);
fv_ensure_column($db, 'fv_sefaz_profile', 'fk_user_modif', 'INTEGER', $error);

fv_ensure_index($db, 'fv_sefaz_profile', 'uk_fv_sefaz_profile_ref', 'UNIQUE KEY uk_fv_sefaz_profile_ref (entity, ref)', $error);

// Ensure Focus job schema
fv_ensure_column($db, 'fv_focus_job', 'entity', 'INTEGER NOT NULL DEFAULT 1', $error);
fv_ensure_column($db, 'fv_focus_job', 'status', 'SMALLINT NOT NULL DEFAULT 0', $error);
fv_ensure_column($db, 'fv_focus_job', 'fk_sefaz_profile', 'INTEGER', $error);
fv_ensure_column($db, 'fv_focus_job', 'job_type', 'VARCHAR(32)', $error);
fv_ensure_column($db, 'fv_focus_job', 'remote_id', 'VARCHAR(64)', $error);
fv_ensure_column($db, 'fv_focus_job', 'attempt_count', 'INTEGER NOT NULL DEFAULT 0', $error);
fv_ensure_column($db, 'fv_focus_job', 'scheduled_for', 'DATETIME', $error);
fv_ensure_column($db, 'fv_focus_job', 'started_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_focus_job', 'finished_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_focus_job', 'payload_json', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_focus_job', 'response_json', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_focus_job', 'error_message', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_focus_job', 'created_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_focus_job', 'updated_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_focus_job', 'fk_user_create', 'INTEGER', $error);
fv_ensure_column($db, 'fv_focus_job', 'fk_user_modif', 'INTEGER', $error);

fv_ensure_index($db, 'fv_focus_job', 'idx_fv_focus_job_sefaz', 'INDEX idx_fv_focus_job_sefaz (fk_sefaz_profile)', $error);
fv_ensure_index($db, 'fv_focus_job', 'idx_fv_focus_job_remote', 'INDEX idx_fv_focus_job_remote (remote_id)', $error);

// Ensure Batch schema
fv_ensure_column($db, 'fv_batch', 'entity', 'INTEGER NOT NULL DEFAULT 1', $error);
fv_ensure_column($db, 'fv_batch', 'status', 'SMALLINT NOT NULL DEFAULT 0', $error);
fv_ensure_column($db, 'fv_batch', 'ref', 'VARCHAR(128) NOT NULL', $error);
fv_ensure_column($db, 'fv_batch', 'fk_partner_profile', 'INTEGER', $error);
fv_ensure_column($db, 'fv_batch', 'fk_sefaz_profile', 'INTEGER', $error);
fv_ensure_column($db, 'fv_batch', 'fk_focus_job', 'INTEGER', $error);
fv_ensure_column($db, 'fv_batch', 'batch_type', 'VARCHAR(32)', $error);
fv_ensure_column($db, 'fv_batch', 'remote_id', 'VARCHAR(64)', $error);
fv_ensure_column($db, 'fv_batch', 'remote_status', 'VARCHAR(32)', $error);
fv_ensure_column($db, 'fv_batch', 'scheduled_for', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch', 'started_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch', 'finished_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch', 'settings_json', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_batch', 'created_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch', 'updated_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch', 'fk_user_create', 'INTEGER', $error);
fv_ensure_column($db, 'fv_batch', 'fk_user_modif', 'INTEGER', $error);

fv_ensure_index($db, 'fv_batch', 'uk_fv_batch_ref', 'UNIQUE KEY uk_fv_batch_ref (entity, ref)', $error);
fv_ensure_index($db, 'fv_batch', 'idx_fv_batch_partner', 'INDEX idx_fv_batch_partner (fk_partner_profile)', $error);
fv_ensure_index($db, 'fv_batch', 'idx_fv_batch_sefaz', 'INDEX idx_fv_batch_sefaz (fk_sefaz_profile)', $error);
fv_ensure_index($db, 'fv_batch', 'idx_fv_batch_focus', 'INDEX idx_fv_batch_focus (fk_focus_job)', $error);
fv_ensure_index($db, 'fv_batch', 'idx_fv_batch_remote', 'INDEX idx_fv_batch_remote (remote_id)', $error);

// Ensure Batch line schema
fv_ensure_column($db, 'fv_batch_line', 'entity', 'INTEGER NOT NULL DEFAULT 1', $error);
fv_ensure_column($db, 'fv_batch_line', 'fk_batch', 'INTEGER NOT NULL', $error);
fv_ensure_column($db, 'fv_batch_line', 'fk_parent_line', 'INTEGER', $error);
fv_ensure_column($db, 'fv_batch_line', 'fk_origin', 'INTEGER', $error);
fv_ensure_column($db, 'fv_batch_line', 'fk_origin_type', 'VARCHAR(64)', $error);
fv_ensure_column($db, 'fv_batch_line', 'line_type', 'VARCHAR(32)', $error);
fv_ensure_column($db, 'fv_batch_line', 'status', 'SMALLINT NOT NULL DEFAULT 0', $error);
fv_ensure_column($db, 'fv_batch_line', 'order_position', 'INTEGER NOT NULL DEFAULT 0', $error);
fv_ensure_column($db, 'fv_batch_line', 'payload_json', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_batch_line', 'response_json', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_batch_line', 'error_message', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_batch_line', 'scheduled_for', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch_line', 'started_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch_line', 'finished_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch_line', 'created_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch_line', 'updated_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch_line', 'fk_user_create', 'INTEGER', $error);

fv_ensure_index($db, 'fv_batch_line', 'idx_fv_batch_line_batch', 'INDEX idx_fv_batch_line_batch (fk_batch)', $error);
fv_ensure_index($db, 'fv_batch_line', 'idx_fv_batch_line_parent', 'INDEX idx_fv_batch_line_parent (fk_parent_line)', $error);
fv_ensure_index($db, 'fv_batch_line', 'idx_fv_batch_line_origin', 'INDEX idx_fv_batch_line_origin (fk_origin, fk_origin_type)', $error);

// Ensure Job schema
fv_ensure_column($db, 'fv_job', 'entity', 'INTEGER NOT NULL DEFAULT 1', $error);
fv_ensure_column($db, 'fv_job', 'status', 'SMALLINT NOT NULL DEFAULT 0', $error);
fv_ensure_column($db, 'fv_job', 'ref', 'VARCHAR(128) NOT NULL', $error);
fv_ensure_column($db, 'fv_job', 'fk_batch', 'INTEGER', $error);
fv_ensure_column($db, 'fv_job', 'fk_batch_line', 'INTEGER', $error);
fv_ensure_column($db, 'fv_job', 'fk_focus_job', 'INTEGER', $error);
fv_ensure_column($db, 'fv_job', 'job_type', 'VARCHAR(32)', $error);
fv_ensure_column($db, 'fv_job', 'job_payload', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_job', 'job_response', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_job', 'error_message', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_job', 'scheduled_for', 'DATETIME', $error);
fv_ensure_column($db, 'fv_job', 'started_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_job', 'finished_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_job', 'attempt_count', 'INTEGER NOT NULL DEFAULT 0', $error);
fv_ensure_column($db, 'fv_job', 'remote_id', 'VARCHAR(64)', $error);
fv_ensure_column($db, 'fv_job', 'remote_status', 'VARCHAR(32)', $error);
fv_ensure_column($db, 'fv_job', 'created_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_job', 'updated_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_job', 'fk_user_create', 'INTEGER', $error);
fv_ensure_column($db, 'fv_job', 'fk_user_modif', 'INTEGER', $error);

fv_ensure_index($db, 'fv_job', 'uk_fv_job_ref', 'UNIQUE KEY uk_fv_job_ref (entity, ref)', $error);
fv_ensure_index($db, 'fv_job', 'idx_fv_job_batch', 'INDEX idx_fv_job_batch (fk_batch)', $error);
fv_ensure_index($db, 'fv_job', 'idx_fv_job_batch_line', 'INDEX idx_fv_job_batch_line (fk_batch_line)', $error);
fv_ensure_index($db, 'fv_job', 'idx_fv_job_focus', 'INDEX idx_fv_job_focus (fk_focus_job)', $error);
fv_ensure_index($db, 'fv_job', 'idx_fv_job_remote', 'INDEX idx_fv_job_remote (remote_id)', $error);

// Ensure Job line schema
fv_ensure_column($db, 'fv_job_line', 'entity', 'INTEGER NOT NULL DEFAULT 1', $error);
fv_ensure_column($db, 'fv_job_line', 'fk_job', 'INTEGER NOT NULL', $error);
fv_ensure_column($db, 'fv_job_line', 'fk_parent_line', 'INTEGER', $error);
fv_ensure_column($db, 'fv_job_line', 'fk_batch_line', 'INTEGER', $error);
fv_ensure_column($db, 'fv_job_line', 'line_type', 'VARCHAR(32)', $error);
fv_ensure_column($db, 'fv_job_line', 'status', 'SMALLINT NOT NULL DEFAULT 0', $error);
fv_ensure_column($db, 'fv_job_line', 'payload_json', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_job_line', 'response_json', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_job_line', 'error_message', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_job_line', 'order_position', 'INTEGER NOT NULL DEFAULT 0', $error);
fv_ensure_column($db, 'fv_job_line', 'scheduled_for', 'DATETIME', $error);
fv_ensure_column($db, 'fv_job_line', 'started_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_job_line', 'finished_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_job_line', 'created_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_job_line', 'updated_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_job_line', 'fk_user_create', 'INTEGER', $error);

fv_ensure_index($db, 'fv_job_line', 'idx_fv_job_line_job', 'INDEX idx_fv_job_line_job (fk_job)', $error);
fv_ensure_index($db, 'fv_job_line', 'idx_fv_job_line_parent', 'INDEX idx_fv_job_line_parent (fk_parent_line)', $error);
fv_ensure_index($db, 'fv_job_line', 'idx_fv_job_line_batch_line', 'INDEX idx_fv_job_line_batch_line (fk_batch_line)', $error);

// Ensure Batch export schema
fv_ensure_column($db, 'fv_batch_export', 'entity', 'INTEGER NOT NULL DEFAULT 1', $error);
fv_ensure_column($db, 'fv_batch_export', 'status', 'SMALLINT NOT NULL DEFAULT 0', $error);
fv_ensure_column($db, 'fv_batch_export', 'ref', 'VARCHAR(128) NOT NULL', $error);
fv_ensure_column($db, 'fv_batch_export', 'export_type', 'VARCHAR(32)', $error);
fv_ensure_column($db, 'fv_batch_export', 'requested_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch_export', 'processed_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch_export', 'file_path', 'VARCHAR(255)', $error);
fv_ensure_column($db, 'fv_batch_export', 'payload_json', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_batch_export', 'error_message', 'MEDIUMTEXT', $error);
fv_ensure_column($db, 'fv_batch_export', 'created_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch_export', 'updated_at', 'DATETIME', $error);
fv_ensure_column($db, 'fv_batch_export', 'fk_user_create', 'INTEGER', $error);
fv_ensure_column($db, 'fv_batch_export', 'fk_user_modif', 'INTEGER', $error);

fv_ensure_index($db, 'fv_batch_export', 'uk_fv_batch_export_ref', 'UNIQUE KEY uk_fv_batch_export_ref (entity, ref)', $error);

if ($error) {
    $db->rollback();
    throw new RuntimeException('Errors occurred during FvFiscal database upgrade to 14.0.1.');
}

$db->commit();

return 0;
