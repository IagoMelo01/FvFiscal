<?php
/* Copyright (C) 2025           SuperAdmin */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Focus (external service) job tracking.
 */
class FvFocusJob extends CommonObject
{
    /** @var string */
    public $element = 'fvfocusjob';

    /** @var string */
    public $table_element = 'fv_focus_job';

    /** @var int */
    public $ismultientitymanaged = 1;

    /** @var int */
    public $isextrafieldmanaged = 1;

    /** @var array<string, array<string, mixed>> */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 1, 'position' => 5),
        'status' => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'default' => 0, 'notnull' => 1),
        'fk_sefaz_profile' => array('type' => 'integer', 'label' => 'SefazProfile', 'enabled' => 1, 'visible' => 1, 'position' => 15, 'foreignkey' => 'fv_sefaz_profile.rowid'),
        'job_type' => array('type' => 'varchar(32)', 'label' => 'JobType', 'enabled' => 1, 'visible' => 1, 'position' => 20),
        'remote_id' => array('type' => 'varchar(64)', 'label' => 'RemoteId', 'enabled' => 1, 'visible' => 1, 'position' => 25),
        'attempt_count' => array('type' => 'integer', 'label' => 'AttemptCount', 'enabled' => 1, 'visible' => 1, 'position' => 30, 'default' => 0),
        'scheduled_for' => array('type' => 'datetime', 'label' => 'DateScheduled', 'enabled' => 1, 'visible' => 1, 'position' => 35),
        'started_at' => array('type' => 'datetime', 'label' => 'DateStart', 'enabled' => 1, 'visible' => 1, 'position' => 40),
        'finished_at' => array('type' => 'datetime', 'label' => 'DateEnd', 'enabled' => 1, 'visible' => 1, 'position' => 45),
        'payload_json' => array('type' => 'text', 'label' => 'PayloadJson', 'enabled' => 1, 'visible' => 0, 'position' => 50),
        'response_json' => array('type' => 'text', 'label' => 'ResponseJson', 'enabled' => 1, 'visible' => 0, 'position' => 55),
        'error_message' => array('type' => 'text', 'label' => 'ErrorMessage', 'enabled' => 1, 'visible' => 0, 'position' => 60),
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
