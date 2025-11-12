<?php

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Event registered for a batch lifecycle.
 */
class FvBatchEvent extends CommonObject
{
    /** @var string */
    public $element = 'fvbatchevent';

    /** @var string */
    public $table_element = 'fv_batch_event';

    /** @var int */
    public $ismultientitymanaged = 1;

    /** @var int */
    public $isextrafieldmanaged = 1;

    /** @var array<string, array<string, mixed>> */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 1, 'position' => 5),
        'fk_batch' => array('type' => 'integer', 'label' => 'Batch', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'foreignkey' => 'fv_batch.rowid'),
        'fk_focus_job' => array('type' => 'integer', 'label' => 'FocusJob', 'enabled' => 1, 'visible' => 0, 'position' => 15, 'foreignkey' => 'fv_focus_job.rowid'),
        'event_type' => array('type' => 'varchar(32)', 'label' => 'EventType', 'enabled' => 1, 'visible' => 1, 'position' => 20),
        'event_payload' => array('type' => 'text', 'label' => 'EventPayload', 'enabled' => 1, 'visible' => 0, 'position' => 25),
        'response_json' => array('type' => 'text', 'label' => 'ResponseJson', 'enabled' => 1, 'visible' => 0, 'position' => 30),
        'error_message' => array('type' => 'text', 'label' => 'ErrorMessage', 'enabled' => 1, 'visible' => 0, 'position' => 35),
        'datetime_created' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => 1, 'position' => 40),
        'fk_user_create' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -1, 'position' => 505, 'foreignkey' => 'user.rowid'),
    );

    /**
     * {@inheritDoc}
     */
    public function create($user, $notrigger = false)
    {
        if (empty($this->datetime_created)) {
            $this->datetime_created = dol_now();
        }
        if (!empty($user)) {
            $this->fk_user_create = $user->id;
        }

        return $this->createCommon($user, $notrigger);
    }

    /**
     * {@inheritDoc}
     */
    public function update($user = null, $notrigger = false)
    {
        return $this->updateCommon($user, $notrigger);
    }

    /**
     * {@inheritDoc}
     */
    public function fetch($id, $ref = null)
    {
        return $this->fetchCommon($id, $ref);
    }
}
