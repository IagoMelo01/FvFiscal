<?php
/* Copyright (C) 2025           SuperAdmin */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Inbound NF-e document representation.
 */
class FvNfeIn extends CommonObject
{
    /** @var string */
    public $element = 'fvnfein';

    /** @var string */
    public $table_element = 'fv_nfe_in';

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
        'ref_ext' => array('type' => 'varchar(128)', 'label' => 'RefExt', 'enabled' => 1, 'visible' => 0, 'position' => 18),
        'fk_soc' => array('type' => 'integer', 'label' => 'ThirdParty', 'enabled' => 1, 'visible' => 1, 'position' => 20, 'notnull' => 1, 'foreignkey' => 'societe.rowid'),
        'fk_project' => array('type' => 'integer', 'label' => 'Project', 'enabled' => 1, 'visible' => 0, 'position' => 23, 'foreignkey' => 'projet.rowid'),
        'doc_type' => array('type' => 'varchar(32)', 'label' => 'DocumentType', 'enabled' => 1, 'visible' => 1, 'position' => 30, 'default' => 'nfe'),
        'operation_type' => array('type' => 'varchar(32)', 'label' => 'OperationType', 'enabled' => 1, 'visible' => 0, 'position' => 32),
        'issue_at' => array('type' => 'datetime', 'label' => 'DateIssue', 'enabled' => 1, 'visible' => 1, 'position' => 35),
        'arrival_at' => array('type' => 'datetime', 'label' => 'DateArrival', 'enabled' => 1, 'visible' => 1, 'position' => 36),
        'nfe_key' => array('type' => 'varchar(60)', 'label' => 'NfeKey', 'enabled' => 1, 'visible' => 1, 'position' => 40, 'index' => 1),
        'xml_path' => array('type' => 'varchar(255)', 'label' => 'XmlPath', 'enabled' => 1, 'visible' => 0, 'position' => 45),
        'pdf_path' => array('type' => 'varchar(255)', 'label' => 'PdfPath', 'enabled' => 1, 'visible' => 0, 'position' => 50),
        'total_products' => array('type' => 'double(24,8)', 'label' => 'TotalProducts', 'enabled' => 1, 'visible' => 1, 'position' => 100, 'default' => 0),
        'total_tax' => array('type' => 'double(24,8)', 'label' => 'TotalTax', 'enabled' => 1, 'visible' => 1, 'position' => 105, 'default' => 0),
        'total_amount' => array('type' => 'double(24,8)', 'label' => 'TotalAmount', 'enabled' => 1, 'visible' => 1, 'position' => 110, 'default' => 0),
        'note_public' => array('type' => 'text', 'label' => 'NotePublic', 'enabled' => 1, 'visible' => 1, 'position' => 200),
        'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => 1, 'visible' => 0, 'position' => 205),
        'json_payload' => array('type' => 'text', 'label' => 'JsonPayload', 'enabled' => 1, 'visible' => 0, 'position' => 300),
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
