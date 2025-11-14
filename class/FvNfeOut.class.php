<?php
/* Copyright (C) 2025           SuperAdmin */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
require_once __DIR__ . '/FvNfeOutLine.class.php';

/**
 * Outbound NF-e document.
 */
class FvNfeOut extends CommonObject
{
    public const STATUS_DRAFT = 0;

    public const STATUS_PROCESSING = 1;

    public const STATUS_AUTHORIZED = 2;

    public const STATUS_ERROR = 3;

    public const STATUS_CANCELLED = 4;

    /** @var string */
    public $element = 'fvnfeout';

    /** @var string */
    public $table_element = 'fv_nfe_out';

    /** @var string */
    public $table_element_line = 'fv_nfe_out_line';

    /** @var string */
    public $fk_element = 'fk_nfeout';

    /** @var string */
    public $class_element_line = 'FvNfeOutLine';

    /** @var int */
    public $ismultientitymanaged = 1;

    /** @var int */
    public $isextrafieldmanaged = 1;

    /** @var array<int, CommonObjectLine> */
    public $lines = array();

    /** @var array<string, array<string, mixed>> */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 1, 'position' => 5),
        'status' => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'default' => self::STATUS_DRAFT, 'notnull' => 1),
        'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'visible' => 1, 'index' => 1, 'position' => 15),
        'ref_ext' => array('type' => 'varchar(128)', 'label' => 'RefExt', 'enabled' => 1, 'visible' => 0, 'position' => 18),
        'fk_soc' => array('type' => 'integer', 'label' => 'ThirdParty', 'enabled' => 1, 'visible' => 1, 'position' => 20, 'notnull' => 1, 'foreignkey' => 'societe.rowid'),
        'fk_project' => array('type' => 'integer', 'label' => 'Project', 'enabled' => 1, 'visible' => 0, 'position' => 23, 'foreignkey' => 'projet.rowid'),
        'fk_sefaz_profile' => array('type' => 'integer', 'label' => 'SefazProfile', 'enabled' => 1, 'visible' => 1, 'position' => 25, 'notnull' => 1, 'foreignkey' => 'fv_sefaz_profile.rowid'),
        'fk_certificate' => array('type' => 'integer', 'label' => 'Certificate', 'enabled' => 1, 'visible' => 1, 'position' => 26, 'foreignkey' => 'fv_certificate.rowid'),
        'fk_batch_export' => array('type' => 'integer', 'label' => 'BatchExport', 'enabled' => 1, 'visible' => 0, 'position' => 27, 'foreignkey' => 'fv_batch_export.rowid'),
        'fk_focus_job' => array('type' => 'integer', 'label' => 'FocusJob', 'enabled' => 1, 'visible' => 0, 'position' => 28, 'foreignkey' => 'fv_focus_job.rowid'),
        'doc_type' => array('type' => 'varchar(32)', 'label' => 'DocumentType', 'enabled' => 1, 'visible' => 1, 'position' => 30, 'default' => 'nfe'),
        'operation_type' => array('type' => 'varchar(32)', 'label' => 'OperationType', 'enabled' => 1, 'visible' => 0, 'position' => 32, 'default' => 'sale'),
        'issue_at' => array('type' => 'datetime', 'label' => 'DateIssue', 'enabled' => 1, 'visible' => 1, 'position' => 35),
        'departure_at' => array('type' => 'datetime', 'label' => 'DateDeparture', 'enabled' => 1, 'visible' => 0, 'position' => 36),
        'nfe_key' => array('type' => 'varchar(60)', 'label' => 'NfeKey', 'enabled' => 1, 'visible' => 1, 'position' => 40, 'index' => 1),
        'protocol_number' => array('type' => 'varchar(64)', 'label' => 'ProtocolNumber', 'enabled' => 1, 'visible' => 0, 'position' => 45),
        'series' => array('type' => 'varchar(12)', 'label' => 'Series', 'enabled' => 1, 'visible' => 1, 'position' => 50),
        'document_number' => array('type' => 'varchar(16)', 'label' => 'DocumentNumber', 'enabled' => 1, 'visible' => 1, 'position' => 55),
        'xml_path' => array('type' => 'varchar(255)', 'label' => 'XmlPath', 'enabled' => 1, 'visible' => 0, 'position' => 60),
        'pdf_path' => array('type' => 'varchar(255)', 'label' => 'PdfPath', 'enabled' => 1, 'visible' => 0, 'position' => 65),
        'total_products' => array('type' => 'double(24,8)', 'label' => 'TotalProducts', 'enabled' => 1, 'visible' => 1, 'position' => 100, 'default' => 0),
        'total_discount' => array('type' => 'double(24,8)', 'label' => 'TotalDiscount', 'enabled' => 1, 'visible' => 1, 'position' => 105, 'default' => 0),
        'total_tax' => array('type' => 'double(24,8)', 'label' => 'TotalTax', 'enabled' => 1, 'visible' => 1, 'position' => 110, 'default' => 0),
        'total_freight' => array('type' => 'double(24,8)', 'label' => 'TotalFreight', 'enabled' => 1, 'visible' => 0, 'position' => 112, 'default' => 0),
        'total_insurance' => array('type' => 'double(24,8)', 'label' => 'TotalInsurance', 'enabled' => 1, 'visible' => 0, 'position' => 114, 'default' => 0),
        'total_other' => array('type' => 'double(24,8)', 'label' => 'TotalOther', 'enabled' => 1, 'visible' => 0, 'position' => 116, 'default' => 0),
        'total_amount' => array('type' => 'double(24,8)', 'label' => 'TotalAmount', 'enabled' => 1, 'visible' => 1, 'position' => 120, 'default' => 0),
        'total_weight' => array('type' => 'double(24,8)', 'label' => 'TotalWeight', 'enabled' => 1, 'visible' => 0, 'position' => 125, 'default' => 0),
        'note_public' => array('type' => 'text', 'label' => 'NotePublic', 'enabled' => 1, 'visible' => 1, 'position' => 200),
        'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => 1, 'visible' => 0, 'position' => 205),
        'json_payload' => array('type' => 'text', 'label' => 'JsonPayload', 'enabled' => 1, 'visible' => 0, 'position' => 300),
        'json_response' => array('type' => 'text', 'label' => 'JsonResponse', 'enabled' => 1, 'visible' => 0, 'position' => 305),
        'created_at' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'position' => 500),
        'updated_at' => array('type' => 'datetime', 'label' => 'Tms', 'enabled' => 1, 'visible' => -1, 'position' => 501),
        'fk_user_create' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -1, 'position' => 505, 'foreignkey' => 'user.rowid'),
        'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => 1, 'visible' => -1, 'position' => 506, 'foreignkey' => 'user.rowid'),
    );

    /**
     * {@inheritDoc}
     */
    public function create($user, $notrigger = false)
    {
        if (empty($this->created_at)) {
            $this->created_at = dol_now();
        }
        $this->fk_user_create = $user->id;

        return $this->createCommon($user, $notrigger);
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
            self::STATUS_DRAFT => $langs->trans('FvFiscalNfeOutStatusPending'),
            self::STATUS_PROCESSING => $langs->trans('FvFiscalNfeOutStatusProcessing'),
            self::STATUS_AUTHORIZED => $langs->trans('FvFiscalNfeOutStatusAuthorized'),
            self::STATUS_ERROR => $langs->trans('FvFiscalNfeOutStatusError'),
            self::STATUS_CANCELLED => $langs->trans('FvFiscalNfeOutStatusCancelled'),
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
     * Determine if document is still pending authorization.
     *
     * @return bool
     */
    public function isDraft()
    {
        return (int) $this->status === self::STATUS_DRAFT || (int) $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Determine if the document can be submitted to Focus for authorization.
     *
     * @return bool
     */
    public function canIssue()
    {
        return (int) $this->status === self::STATUS_DRAFT;
    }

    /**
     * Determine if document has been authorized by SEFAZ.
     *
     * @return bool
     */
    public function isAuthorized()
    {
        return (int) $this->status === self::STATUS_AUTHORIZED;
    }

    /**
     * Determine if document has been cancelled.
     *
     * @return bool
     */
    public function isCancelled()
    {
        return (int) $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Check if cancellation can be requested.
     *
     * @return bool
     */
    public function canSendCancellation()
    {
        return $this->isAuthorized();
    }

    /**
     * Check if a correction letter can be emitted.
     *
     * @return bool
     */
    public function canSendCorrection()
    {
        return $this->isAuthorized();
    }

    /**
     * Retrieve a human readable identifier for the document.
     *
     * @return string
     */
    public function getDisplayLabel()
    {
        $parts = array();
        if (!empty($this->series)) {
            $parts[] = $this->series;
        }
        if (!empty($this->document_number)) {
            $parts[] = $this->document_number;
        }
        if (!empty($parts)) {
            return implode('/', $parts);
        }
        if (!empty($this->ref)) {
            return $this->ref;
        }
        if (!empty($this->nfe_key)) {
            return $this->nfe_key;
        }

        return (string) $this->id;
    }

    /**
     * {@inheritDoc}
     */
    public function update($user = null, $notrigger = false)
    {
        $this->updated_at = dol_now();
        if ($user) {
            $this->fk_user_modif = $user->id;
        }

        return $this->updateCommon($user, $notrigger);
    }

    /**
     * {@inheritDoc}
     */
    public function fetch($id, $ref = null, $loadChild = true)
    {
        $result = $this->fetchCommon($id, $ref);
        if ($result > 0 && $loadChild) {
            $this->fetchLines(true);
        }

        return $result;
    }

    /**
     * Load document lines from the database.
     *
     * @param bool $force If true, always reload from database.
     * @return int Status code (<0 on error)
     */
    public function fetchLines($force = false)
    {
        if (!$force && !empty($this->lines)) {
            return count($this->lines);
        }

        $this->lines = array();

        if (empty($this->id)) {
            return 0;
        }

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . $this->table_element_line . ' WHERE fk_nfeout = ' . ((int) $this->id) . ' ORDER BY rang ASC, rowid ASC';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $line = new FvNfeOutLine($this->db);
            $result = $line->fetch($obj->rowid);
            if ($result >= 0) {
                $this->lines[] = $line;
            } else {
                $this->db->free($resql);
                $this->error = $line->error;
                return -1;
            }
        }
        $this->db->free($resql);

        return count($this->lines);
    }

    /**
     * Create a new line for the invoice.
     *
     * @param User  $user  Current user
     * @param array $data  Associative array of field => value
     * @return int Positive rowid on success, <0 on error
     */
    public function addLine($user, array $data)
    {
        if (empty($this->id)) {
            $this->error = 'ObjectNotPersisted';
            return -1;
        }

        $line = new FvNfeOutLine($this->db);
        $line->fk_nfeout = $this->id;
        $line->entity = $this->entity;

        foreach ($data as $field => $value) {
            if (array_key_exists($field, $line->fields)) {
                $line->{$field} = $value;
            }
        }

        $this->db->begin();
        $result = $line->create($user);
        if ($result < 0) {
            $this->db->rollback();
            $this->error = $line->error;
            return -1;
        }

        $this->fetchLines(true);
        $updateResult = $this->recalculateTotals($user);
        if ($updateResult < 0) {
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();
        return $result;
    }

    /**
     * Update an existing line.
     *
     * @param User  $user    Current user
     * @param int   $lineId  Identifier of the line to update
     * @param array $data    Field list to update
     * @return int >=0 on success, <0 on failure
     */
    public function updateLine($user, $lineId, array $data)
    {
        $line = new FvNfeOutLine($this->db);
        if ($line->fetch($lineId) <= 0) {
            $this->error = $line->error ?: 'LineNotFound';
            return -1;
        }

        foreach ($data as $field => $value) {
            if (array_key_exists($field, $line->fields)) {
                $line->{$field} = $value;
            }
        }

        $this->db->begin();
        $result = $line->update($user);
        if ($result < 0) {
            $this->db->rollback();
            $this->error = $line->error;
            return -1;
        }

        $this->fetchLines(true);
        $updateResult = $this->recalculateTotals($user);
        if ($updateResult < 0) {
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();
        return $result;
    }

    /**
     * Delete a line from the document.
     *
     * @param User $user   Current user
     * @param int  $lineId Line identifier
     * @return int >=0 on success, <0 on failure
     */
    public function deleteLine($user, $lineId)
    {
        $line = new FvNfeOutLine($this->db);
        if ($line->fetch($lineId) <= 0) {
            $this->error = $line->error ?: 'LineNotFound';
            return -1;
        }

        $this->db->begin();
        $result = $line->delete($user);
        if ($result < 0) {
            $this->db->rollback();
            $this->error = $line->error;
            return -1;
        }

        $this->fetchLines(true);
        $updateResult = $this->recalculateTotals($user);
        if ($updateResult < 0) {
            $this->db->rollback();
            return -1;
        }

        $this->db->commit();
        return $result;
    }

    /**
     * Recalculate totals using current lines.
     *
     * @param User|null $user Current user to stamp update metadata
     * @return int >=0 on success, <0 on failure
     */
    public function recalculateTotals($user = null)
    {
        if (empty($this->lines)) {
            $this->fetchLines(true);
        }

        $totalProducts = 0;
        $totalDiscount = 0;
        $totalTaxes = 0;
        $totalAmount = 0;

        foreach ($this->lines as $line) {
            $qty = price2num($line->qty, 'MU');
            $unit = price2num($line->unit_price, 'MU');
            $gross = price2num($qty * $unit, 'MT');
            $discount = price2num((float) $line->discount_amount, 'MT');
            if (!$discount && !empty($line->discount_percent)) {
                $discount = price2num($gross * ((float) $line->discount_percent) / 100, 'MT');
            }

            $lineTotalHT = price2num(!empty($line->total_ht) ? $line->total_ht : ($gross - $discount), 'MT');
            $lineTaxes = price2num(!empty($line->total_taxes) ? $line->total_taxes : (
                (float) $line->icms_amount + (float) $line->ipi_amount + (float) $line->pis_amount + (float) $line->cofins_amount + (float) $line->issqn_amount
            ), 'MT');
            $lineTotalTTC = price2num(!empty($line->total_ttc) ? $line->total_ttc : ($lineTotalHT + $lineTaxes), 'MT');

            $totalProducts += $lineTotalHT;
            $totalDiscount += $discount;
            $totalTaxes += $lineTaxes;
            $totalAmount += $lineTotalTTC;
        }

        $this->total_products = price2num($totalProducts, 'MT');
        $this->total_discount = price2num($totalDiscount, 'MT');
        $this->total_tax = price2num($totalTaxes, 'MT');
        $this->total_amount = price2num($totalAmount, 'MT');

        if ($user) {
            $this->updated_at = dol_now();
            $this->fk_user_modif = $user->id;
        }

        return $this->updateCommon($user, true);
    }
}
