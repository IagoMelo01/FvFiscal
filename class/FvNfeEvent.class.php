<?php
/* Copyright (C) 2025           SuperAdmin */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * NF-e event (cancellation, correction letter, etc.).
 */
class FvNfeEvent extends CommonObject
{
    /** @var string */
    public $element = 'fvnfeevent';

    /** @var string */
    public $table_element = 'fv_nfe_event';

    /** @var int */
    public $ismultientitymanaged = 1;

    /** @var int */
    public $isextrafieldmanaged = 1;

    /** @var array<string, array<string, mixed>> */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 1, 'position' => 5),
        'status' => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'default' => 0, 'notnull' => 1),
        'fk_nfeout' => array('type' => 'integer', 'label' => 'FkNfeOut', 'enabled' => 1, 'visible' => 1, 'position' => 15, 'notnull' => 1, 'foreignkey' => 'fv_nfe_out.rowid'),
        'event_type' => array('type' => 'varchar(32)', 'label' => 'EventType', 'enabled' => 1, 'visible' => 1, 'position' => 20),
        'event_sequence' => array('type' => 'integer', 'label' => 'EventSequence', 'enabled' => 1, 'visible' => 0, 'position' => 25, 'default' => 1),
        'protocol_number' => array('type' => 'varchar(64)', 'label' => 'ProtocolNumber', 'enabled' => 1, 'visible' => 1, 'position' => 30),
        'received_at' => array('type' => 'datetime', 'label' => 'DateReceived', 'enabled' => 1, 'visible' => 1, 'position' => 35),
        'description' => array('type' => 'text', 'label' => 'Description', 'enabled' => 1, 'visible' => 1, 'position' => 40),
        'xml_path' => array('type' => 'varchar(255)', 'label' => 'XmlPath', 'enabled' => 1, 'visible' => 0, 'position' => 45),
        'json_payload' => array('type' => 'text', 'label' => 'JsonPayload', 'enabled' => 1, 'visible' => 0, 'position' => 50),
        'json_response' => array('type' => 'text', 'label' => 'JsonResponse', 'enabled' => 1, 'visible' => 0, 'position' => 55),
        'created_at' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'position' => 500),
        'fk_user_create' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -1, 'position' => 505, 'foreignkey' => 'user.rowid'),
    );

    public function create($user, $notrigger = false)
    {
        if (empty($this->created_at)) {
            $this->created_at = dol_now();
        }
        $this->fk_user_create = $user->id;

        return $this->createCommon($user, $notrigger);
    }

    public function fetch($id, $ref = null)
    {
        return $this->fetchCommon($id, $ref);
    }
}
