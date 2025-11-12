<?php
/* Copyright (C) 2025           SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Data provider for FvFiscal UI widgets.
 */
class FvFiscalDataService
{
    /** @var DoliDB */
    private $db;

    /** @var string */
    private $error = '';

    /** @var array<int, string> */
    private $errors = array();

    /**
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        $this->db = $db;
    }

    /**
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @return array<int, string>
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Fetch batches filtered by partner or product.
     *
     * @param int $socid Thirdparty filter
     * @param int $productId Product filter
     * @return array<int, array<string, mixed>>|false
     */
    public function fetchBatches($socid = 0, $productId = 0)
    {
        $this->resetErrors();

        $sql = "SELECT b.rowid, b.ref, b.status, b.batch_type, b.remote_status, b.scheduled_for, b.started_at, b.finished_at,";
        $sql .= " partner.rowid AS fk_partner_profile, partner.ref AS partner_ref, s.rowid AS socid, s.nom AS soc_name,";
        $sql .= " focus.rowid AS focus_job_id, focus.job_type AS focus_job_type";
        $sql .= " FROM ".MAIN_DB_PREFIX."fv_batch AS b";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."fv_partner_profile AS partner ON partner.rowid = b.fk_partner_profile";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = partner.fk_soc";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."fv_focus_job AS focus ON focus.rowid = b.fk_focus_job";
        if ($productId > 0) {
            $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."fv_batch_line AS bl ON bl.fk_batch = b.rowid";
            $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."fv_nfe_out_line AS nol ON (nol.rowid = bl.fk_origin AND bl.fk_origin_type = 'fvnfeoutline')";
        }
        $sql .= " WHERE b.entity IN (".getEntity('fv_batch').")";
        if ($socid > 0) {
            $sql .= " AND partner.fk_soc = ".((int) $socid);
        }
        if ($productId > 0) {
            $sql .= " AND nol.fk_product = ".((int) $productId);
        }
        $sql .= " GROUP BY b.rowid, b.ref, b.status, b.batch_type, b.remote_status, b.scheduled_for, b.started_at, b.finished_at,";
        $sql .= " partner.rowid, partner.ref, s.rowid, s.nom, focus.rowid, focus.job_type";
        $sql .= " ORDER BY b.scheduled_for DESC, b.rowid DESC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return $this->setErrorFromDb();
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'rowid' => (int) $obj->rowid,
                'ref' => $obj->ref,
                'status' => (int) $obj->status,
                'batch_type' => $obj->batch_type,
                'remote_status' => $obj->remote_status,
                'scheduled_for' => $this->db->jdate($obj->scheduled_for),
                'started_at' => $this->db->jdate($obj->started_at),
                'finished_at' => $this->db->jdate($obj->finished_at),
                'partner_profile_id' => (int) $obj->fk_partner_profile,
                'partner_ref' => $obj->partner_ref,
                'socid' => (int) $obj->socid,
                'soc_name' => $obj->soc_name,
                'focus_job_id' => (int) $obj->focus_job_id,
                'focus_job_type' => $obj->focus_job_type,
            );
        }
        $this->db->free($resql);

        return $rows;
    }

    /**
     * Fetch batch details.
     *
     * @param int $batchId
     * @return array<string, mixed>|null|false
     */
    public function fetchBatchDetail($batchId)
    {
        $this->resetErrors();

        $sql = "SELECT b.rowid, b.ref, b.status, b.batch_type, b.remote_id, b.remote_status, b.settings_json,";
        $sql .= " b.scheduled_for, b.started_at, b.finished_at, b.created_at, b.updated_at, b.fk_partner_profile, b.fk_sefaz_profile,";
        $sql .= " b.fk_focus_job, partner.ref AS partner_ref, partner.fk_soc, s.nom AS soc_name,";
        $sql .= " sefaz.ref AS sefaz_ref, focus.job_type AS focus_job_type, focus.remote_id AS focus_remote_id";
        $sql .= " FROM ".MAIN_DB_PREFIX."fv_batch AS b";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."fv_partner_profile AS partner ON partner.rowid = b.fk_partner_profile";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = partner.fk_soc";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."fv_sefaz_profile AS sefaz ON sefaz.rowid = b.fk_sefaz_profile";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."fv_focus_job AS focus ON focus.rowid = b.fk_focus_job";
        $sql .= " WHERE b.rowid = ".((int) $batchId);
        $sql .= " AND b.entity IN (".getEntity('fv_batch').")";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return $this->setErrorFromDb();
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if (!$obj) {
            return null;
        }

        return array(
            'rowid' => (int) $obj->rowid,
            'ref' => $obj->ref,
            'status' => (int) $obj->status,
            'batch_type' => $obj->batch_type,
            'remote_id' => $obj->remote_id,
            'remote_status' => $obj->remote_status,
            'settings_json' => $obj->settings_json,
            'scheduled_for' => $this->db->jdate($obj->scheduled_for),
            'started_at' => $this->db->jdate($obj->started_at),
            'finished_at' => $this->db->jdate($obj->finished_at),
            'created_at' => $this->db->jdate($obj->created_at),
            'updated_at' => $this->db->jdate($obj->updated_at),
            'fk_partner_profile' => (int) $obj->fk_partner_profile,
            'fk_soc' => (int) $obj->fk_soc,
            'partner_ref' => $obj->partner_ref,
            'soc_name' => $obj->soc_name,
            'fk_sefaz_profile' => (int) $obj->fk_sefaz_profile,
            'sefaz_ref' => $obj->sefaz_ref,
            'fk_focus_job' => (int) $obj->fk_focus_job,
            'focus_job_type' => $obj->focus_job_type,
            'focus_remote_id' => $obj->focus_remote_id,
        );
    }

    /**
     * Fetch batch lines, optionally filtered by product.
     *
     * @param int $batchId
     * @param int $productId
     * @return array<int, array<string, mixed>>|false
     */
    public function fetchBatchLines($batchId, $productId = 0)
    {
        $this->resetErrors();

        $sql = "SELECT bl.rowid, bl.line_type, bl.status, bl.order_position, bl.fk_origin, bl.fk_origin_type,";
        $sql .= " bl.scheduled_for, bl.started_at, bl.finished_at, nol.fk_product, p.ref AS product_ref, p.label AS product_label";
        $sql .= " FROM ".MAIN_DB_PREFIX."fv_batch_line AS bl";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."fv_nfe_out_line AS nol ON (nol.rowid = bl.fk_origin AND bl.fk_origin_type = 'fvnfeoutline')";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = nol.fk_product";
        $sql .= " WHERE bl.fk_batch = ".((int) $batchId);
        $sql .= " AND bl.entity IN (".getEntity('fv_batch').")";
        if ($productId > 0) {
            $sql .= " AND nol.fk_product = ".((int) $productId);
        }
        $sql .= " ORDER BY bl.order_position ASC, bl.rowid ASC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return $this->setErrorFromDb();
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'rowid' => (int) $obj->rowid,
                'line_type' => $obj->line_type,
                'status' => (int) $obj->status,
                'order_position' => (int) $obj->order_position,
                'fk_origin' => (int) $obj->fk_origin,
                'fk_origin_type' => $obj->fk_origin_type,
                'scheduled_for' => $this->db->jdate($obj->scheduled_for),
                'started_at' => $this->db->jdate($obj->started_at),
                'finished_at' => $this->db->jdate($obj->finished_at),
                'fk_product' => (int) $obj->fk_product,
                'product_ref' => $obj->product_ref,
                'product_label' => $obj->product_label,
            );
        }
        $this->db->free($resql);

        return $rows;
    }

    /**
     * Fetch batch events.
     *
     * @param int $batchId
     * @return array<int, array<string, mixed>>|false
     */
    public function fetchBatchEvents($batchId)
    {
        $this->resetErrors();

        $sql = "SELECT e.rowid, e.event_type, e.error_message, e.datetime_created, e.fk_focus_job";
        $sql .= " FROM ".MAIN_DB_PREFIX."fv_batch_event AS e";
        $sql .= " WHERE e.fk_batch = ".((int) $batchId);
        $sql .= " AND e.entity IN (".getEntity('fv_batch').")";
        $sql .= " ORDER BY e.datetime_created DESC, e.rowid DESC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return $this->setErrorFromDb();
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'rowid' => (int) $obj->rowid,
                'event_type' => $obj->event_type,
                'error_message' => $obj->error_message,
                'datetime_created' => $this->db->jdate($obj->datetime_created),
                'fk_focus_job' => (int) $obj->fk_focus_job,
            );
        }
        $this->db->free($resql);

        return $rows;
    }

    /**
     * Fetch jobs associated with a batch.
     *
     * @param int $batchId
     * @return array<int, array<string, mixed>>|false
     */
    public function fetchBatchJobs($batchId)
    {
        $this->resetErrors();

        $sql = "SELECT j.rowid, j.ref, j.status, j.job_type, j.remote_status, j.remote_id, j.scheduled_for, j.started_at, j.finished_at";
        $sql .= " FROM ".MAIN_DB_PREFIX."fv_job AS j";
        $sql .= " WHERE j.fk_batch = ".((int) $batchId);
        $sql .= " AND j.entity IN (".getEntity('fv_job').")";
        $sql .= " ORDER BY j.rowid DESC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return $this->setErrorFromDb();
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'rowid' => (int) $obj->rowid,
                'ref' => $obj->ref,
                'status' => (int) $obj->status,
                'job_type' => $obj->job_type,
                'remote_status' => $obj->remote_status,
                'remote_id' => $obj->remote_id,
                'scheduled_for' => $this->db->jdate($obj->scheduled_for),
                'started_at' => $this->db->jdate($obj->started_at),
                'finished_at' => $this->db->jdate($obj->finished_at),
            );
        }
        $this->db->free($resql);

        return $rows;
    }

    /**
     * Fetch NF-e documents related to a batch.
     *
     * @param int $batchId
     * @param int $focusJobId
     * @param int $productId
     * @return array<int, array<string, mixed>>|false
     */
    public function fetchBatchDocuments($batchId, $focusJobId = 0, $productId = 0)
    {
        $this->resetErrors();

        $sql = "SELECT DISTINCT o.rowid, o.ref, o.status, o.doc_type, o.issue_at, o.total_amount, o.fk_soc, s.nom AS soc_name";
        $sql .= " FROM ".MAIN_DB_PREFIX."fv_nfe_out AS o";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = o.fk_soc";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."fv_job AS j ON j.fk_focus_job = o.fk_focus_job";
        $sql .= " WHERE o.entity IN (".getEntity('fv_nfe_out').")";
        $sql .= " AND (j.fk_batch = ".((int) $batchId);
        if ($focusJobId > 0) {
            $sql .= " OR o.fk_focus_job = ".((int) $focusJobId);
        }
        $sql .= ")";
        if ($productId > 0) {
            $sql .= " AND EXISTS (SELECT 1 FROM ".MAIN_DB_PREFIX."fv_nfe_out_line AS l WHERE l.fk_nfeout = o.rowid AND l.fk_product = ".((int) $productId).")";
        }
        $sql .= " ORDER BY o.issue_at DESC, o.rowid DESC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            return $this->setErrorFromDb();
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'rowid' => (int) $obj->rowid,
                'ref' => $obj->ref,
                'status' => (int) $obj->status,
                'doc_type' => $obj->doc_type,
                'issue_at' => $this->db->jdate($obj->issue_at),
                'total_amount' => (float) $obj->total_amount,
                'fk_soc' => (int) $obj->fk_soc,
                'soc_name' => $obj->soc_name,
            );
        }
        $this->db->free($resql);

        return $rows;
    }

    /**
     * Reset service errors.
     *
     * @return void
     */
    private function resetErrors()
    {
        $this->error = '';
        $this->errors = array();
    }

    /**
     * @return false
     */
    private function setErrorFromDb()
    {
        $this->error = $this->db->lasterror();
        $this->errors = array();
        if (!empty($this->error)) {
            $this->errors[] = $this->error;
        }

        return false;
    }
}
