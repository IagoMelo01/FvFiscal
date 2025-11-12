<?php
/* Copyright (C) 2025           SuperAdmin */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * MDF-e manifest representation.
 */
class FvMdfe extends CommonObject
{
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
        'fk_batch_export' => array('type' => 'integer', 'label' => 'BatchExport', 'enabled' => 1, 'visible' => 0, 'position' => 23, 'foreignkey' => 'fv_batch_export.rowid'),
        'fk_focus_job' => array('type' => 'integer', 'label' => 'FocusJob', 'enabled' => 1, 'visible' => 0, 'position' => 24, 'foreignkey' => 'fv_focus_job.rowid'),
        'issue_at' => array('type' => 'datetime', 'label' => 'DateIssue', 'enabled' => 1, 'visible' => 1, 'position' => 30),
        'mdfe_key' => array('type' => 'varchar(60)', 'label' => 'MdfeKey', 'enabled' => 1, 'visible' => 1, 'position' => 35, 'index' => 1),
        'protocol_number' => array('type' => 'varchar(64)', 'label' => 'ProtocolNumber', 'enabled' => 1, 'visible' => 1, 'position' => 40),
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
        return $this->fetchCommon($id, $ref);
    }
}
