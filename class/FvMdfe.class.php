<?php
/* Copyright (C) 2025           SuperAdmin */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';

/**
 * MDF-e manifest representation.
 */
class FvMdfe extends CommonObject
{
    public const STATUS_DRAFT = 0;

    public const STATUS_PROCESSING = 1;

    public const STATUS_AUTHORIZED = 2;

    public const STATUS_CANCELLED = 3;

    public const STATUS_CLOSED = 4;

    /** @var string */
    public $element = 'fvmdfe';

    /** @var string */
    public $table_element = 'fv_mdfe';

    /** @var int */
    public $ismultientitymanaged = 1;

    /** @var int */
    public $isextrafieldmanaged = 1;

    /** @var array<string, array<string, mixed>> */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 1, 'position' => 5),
        'status' => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'default' => 0, 'notnull' => 1),
        'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'visible' => 1, 'index' => 1, 'position' => 15),
        'fk_sefaz_profile' => array('type' => 'integer', 'label' => 'SefazProfile', 'enabled' => 1, 'visible' => 1, 'position' => 20, 'foreignkey' => 'fv_sefaz_profile.rowid'),
        'fk_certificate' => array('type' => 'integer', 'label' => 'Certificate', 'enabled' => 1, 'visible' => 0, 'position' => 21, 'foreignkey' => 'fv_certificate.rowid'),
        'fk_batch_export' => array('type' => 'integer', 'label' => 'BatchExport', 'enabled' => 1, 'visible' => 0, 'position' => 23, 'foreignkey' => 'fv_batch_export.rowid'),
        'fk_focus_job' => array('type' => 'integer', 'label' => 'FocusJob', 'enabled' => 1, 'visible' => 0, 'position' => 24, 'foreignkey' => 'fv_focus_job.rowid'),
        'issue_at' => array('type' => 'datetime', 'label' => 'DateIssue', 'enabled' => 1, 'visible' => 1, 'position' => 30),
        'mdfe_key' => array('type' => 'varchar(60)', 'label' => 'MdfeKey', 'enabled' => 1, 'visible' => 1, 'position' => 35, 'index' => 1),
        'protocol_number' => array('type' => 'varchar(64)', 'label' => 'ProtocolNumber', 'enabled' => 1, 'visible' => 1, 'position' => 40),
        'vehicle_plate' => array('type' => 'varchar(16)', 'label' => 'VehiclePlate', 'enabled' => 1, 'visible' => 1, 'position' => 41),
        'vehicle_rntrc' => array('type' => 'varchar(32)', 'label' => 'VehicleRntrc', 'enabled' => 1, 'visible' => 0, 'position' => 42),
        'driver_name' => array('type' => 'varchar(128)', 'label' => 'DriverName', 'enabled' => 1, 'visible' => 1, 'position' => 43),
        'driver_document' => array('type' => 'varchar(32)', 'label' => 'DriverDocument', 'enabled' => 1, 'visible' => 1, 'position' => 44),
        'origin_city' => array('type' => 'varchar(128)', 'label' => 'OriginCity', 'enabled' => 1, 'visible' => 1, 'position' => 45),
        'origin_state' => array('type' => 'varchar(4)', 'label' => 'OriginState', 'enabled' => 1, 'visible' => 1, 'position' => 46),
        'destination_city' => array('type' => 'varchar(128)', 'label' => 'DestinationCity', 'enabled' => 1, 'visible' => 1, 'position' => 47),
        'destination_state' => array('type' => 'varchar(4)', 'label' => 'DestinationState', 'enabled' => 1, 'visible' => 1, 'position' => 48),
        'closure_at' => array('type' => 'datetime', 'label' => 'ClosureDate', 'enabled' => 1, 'visible' => 0, 'position' => 49),
        'closure_city' => array('type' => 'varchar(128)', 'label' => 'ClosureCity', 'enabled' => 1, 'visible' => 0, 'position' => 50),
        'closure_state' => array('type' => 'varchar(4)', 'label' => 'ClosureState', 'enabled' => 1, 'visible' => 0, 'position' => 51),
        'total_ctes' => array('type' => 'integer', 'label' => 'TotalCtes', 'enabled' => 1, 'visible' => 1, 'position' => 50, 'default' => 0),
        'total_weight' => array('type' => 'double(24,8)', 'label' => 'TotalWeight', 'enabled' => 1, 'visible' => 1, 'position' => 55, 'default' => 0),
        'total_value' => array('type' => 'double(24,8)', 'label' => 'TotalValue', 'enabled' => 1, 'visible' => 1, 'position' => 60, 'default' => 0),
        'xml_path' => array('type' => 'varchar(255)', 'label' => 'XmlPath', 'enabled' => 1, 'visible' => 0, 'position' => 70),
        'pdf_path' => array('type' => 'varchar(255)', 'label' => 'PdfPath', 'enabled' => 1, 'visible' => 0, 'position' => 75),
        'json_payload' => array('type' => 'text', 'label' => 'JsonPayload', 'enabled' => 1, 'visible' => 0, 'position' => 80),
        'json_response' => array('type' => 'text', 'label' => 'JsonResponse', 'enabled' => 1, 'visible' => 0, 'position' => 85),
        'created_at' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'position' => 500),
        'updated_at' => array('type' => 'datetime', 'label' => 'Tms', 'enabled' => 1, 'visible' => -1, 'position' => 501),
        'fk_user_create' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -1, 'position' => 505, 'foreignkey' => 'user.rowid'),
        'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => 1, 'visible' => -1, 'position' => 506, 'foreignkey' => 'user.rowid'),
    );

    /** @var array<int, int> */
    public $linked_nfe_ids = array();

    public function create($user, $notrigger = false)
    {
        if (empty($this->created_at)) {
            $this->created_at = dol_now();
        }
        $this->fk_user_create = $user->id;

        return $this->createCommon($user, $notrigger);
    }

    public function update($user = null, $notrigger = false)
    {
        $this->updated_at = dol_now();
        if ($user) {
            $this->fk_user_modif = $user->id;
        }

        return $this->updateCommon($user, $notrigger);
    }

    public function fetch($id, $ref = null)
    {
        $result = $this->fetchCommon($id, $ref);
        if ($result > 0) {
            $this->linked_nfe_ids = $this->loadLinkedNfeIds();
        }

        return $result;
    }

    /**
     * Retrieve translated status labels.
     *
     * @param Translate $langs
     * @return array<int, string>
     */
    public static function getStatusLabels($langs)
    {
        return array(
            self::STATUS_DRAFT => $langs->trans('FvFiscalMdfeStatusDraft'),
            self::STATUS_PROCESSING => $langs->trans('FvFiscalMdfeStatusProcessing'),
            self::STATUS_AUTHORIZED => $langs->trans('FvFiscalMdfeStatusAuthorized'),
            self::STATUS_CANCELLED => $langs->trans('FvFiscalMdfeStatusCancelled'),
            self::STATUS_CLOSED => $langs->trans('FvFiscalMdfeStatusClosed'),
        );
    }

    /**
     * Resolve translated label for current status.
     *
     * @param Translate $langs
     * @return string
     */
    public function getStatusLabel($langs)
    {
        $labels = self::getStatusLabels($langs);

        return isset($labels[(int) $this->status]) ? $labels[(int) $this->status] : $langs->trans('Unknown');
    }

    /**
     * Determine if document is still open (not cancelled or closed).
     *
     * @return bool
     */
    public function isOpen()
    {
        return (int) $this->status === self::STATUS_DRAFT
            || (int) $this->status === self::STATUS_PROCESSING
            || (int) $this->status === self::STATUS_AUTHORIZED;
    }

    /**
     * Check if cancellation is allowed.
     *
     * @return bool
     */
    public function canCancel()
    {
        if ((int) $this->status !== self::STATUS_AUTHORIZED) {
            return false;
        }
        if (!empty($this->issue_at)) {
            $limit = (int) $this->issue_at + (24 * 3600);
            if (dol_now() > $limit) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if manifest can be closed.
     *
     * @return bool
     */
    public function canClose()
    {
        return (int) $this->status === self::STATUS_AUTHORIZED;
    }

    /**
     * Load linked NF-e identifiers from database.
     *
     * @return array<int, int>
     */
    public function loadLinkedNfeIds()
    {
        if ($this->id <= 0) {
            return array();
        }

        $sql = 'SELECT fk_nfeout FROM ' . MAIN_DB_PREFIX . "fv_mdfe_nfe WHERE fk_mdfe = " . ((int) $this->id) . ' ORDER BY fk_nfeout';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();

            return array();
        }

        $ids = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $ids[] = (int) $obj->fk_nfeout;
        }
        $this->db->free($resql);

        return $ids;
    }

    /**
     * Synchronize MDF-e linked NF-e identifiers.
     *
     * @param array<int|string> $nfeIds
     * @return int
     */
    public function syncLinkedNfes(array $nfeIds)
    {
        if ($this->id <= 0) {
            $this->error = 'MdfeNotPersisted';

            return -1;
        }

        $normalized = array();
        foreach ($nfeIds as $nfeId) {
            $value = (int) $nfeId;
            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }
        $nfeIds = array_values($normalized);

        $this->db->begin();

        $openStatuses = array(self::STATUS_DRAFT, self::STATUS_PROCESSING, self::STATUS_AUTHORIZED);
        if (!empty($nfeIds)) {
            $placeholders = implode(',', array_map('intval', $nfeIds));
            $sqlCheck = 'SELECT mn.fk_nfeout, md.rowid'
                . ' FROM ' . MAIN_DB_PREFIX . 'fv_mdfe_nfe as mn'
                . ' INNER JOIN ' . MAIN_DB_PREFIX . 'fv_mdfe as md ON md.rowid = mn.fk_mdfe'
                . ' WHERE mn.fk_nfeout IN (' . $placeholders . ')'
                . ' AND mn.fk_mdfe <> ' . ((int) $this->id)
                . ' AND md.status IN (' . implode(',', array_map('intval', $openStatuses)) . ')';
            $resCheck = $this->db->query($sqlCheck);
            if (!$resCheck) {
                $this->db->rollback();
                $this->error = $this->db->lasterror();

                return -1;
            }
            if ($this->db->num_rows($resCheck) > 0) {
                $this->db->free($resCheck);
                $this->db->rollback();
                $this->error = 'MdfeDuplicateNfeLink';

                return -1;
            }
            $this->db->free($resCheck);
        }

        $sqlDelete = 'DELETE FROM ' . MAIN_DB_PREFIX . 'fv_mdfe_nfe WHERE fk_mdfe = ' . ((int) $this->id);
        if (!empty($nfeIds)) {
            $sqlDelete .= ' AND fk_nfeout NOT IN (' . implode(',', array_map('intval', $nfeIds)) . ')';
        }
        if (!$this->db->query($sqlDelete)) {
            $this->db->rollback();
            $this->error = $this->db->lasterror();

            return -1;
        }

        foreach ($nfeIds as $nfeId) {
            $sqlExists = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . 'fv_mdfe_nfe'
                . ' WHERE fk_mdfe = ' . ((int) $this->id)
                . ' AND fk_nfeout = ' . ((int) $nfeId) . ' LIMIT 1';
            $resExists = $this->db->query($sqlExists);
            if (!$resExists) {
                $this->db->rollback();
                $this->error = $this->db->lasterror();

                return -1;
            }
            $exists = $this->db->fetch_object($resExists);
            $this->db->free($resExists);
            if ($exists) {
                continue;
            }

            $sqlInsert = 'INSERT INTO ' . MAIN_DB_PREFIX . "fv_mdfe_nfe (fk_mdfe, fk_nfeout, created_at) VALUES ("
                . ((int) $this->id) . ', ' . ((int) $nfeId) . ", '" . $this->db->idate(dol_now()) . "')";
            if (!$this->db->query($sqlInsert)) {
                $this->db->rollback();
                $this->error = $this->db->lasterror();

                return -1;
            }
        }

        $this->db->commit();

        $this->linked_nfe_ids = $nfeIds;

        $this->recalculateTotals();

        return 1;
    }

    /**
     * Recalculate totals based on linked NF-e documents.
     *
     * @return void
     */
    public function recalculateTotals()
    {
        if ($this->id <= 0) {
            return;
        }

        $sql = 'SELECT COUNT(*) as total_docs, SUM(o.total_weight) as weight_sum, SUM(o.total_amount) as amount_sum'
            . ' FROM ' . MAIN_DB_PREFIX . 'fv_mdfe_nfe as mn'
            . ' INNER JOIN ' . MAIN_DB_PREFIX . 'fv_nfe_out as o ON o.rowid = mn.fk_nfeout'
            . ' WHERE mn.fk_mdfe = ' . ((int) $this->id);
        $resql = $this->db->query($sql);
        if (!$resql) {
            return;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if ($obj) {
            $this->total_ctes = (int) $obj->total_docs;
            $this->total_weight = price2num((float) ($obj->weight_sum ?: 0), 'MT');
            $this->total_value = price2num((float) ($obj->amount_sum ?: 0), 'MT');
        }
    }
}
