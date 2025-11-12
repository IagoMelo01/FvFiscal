<?php

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobjectline.class.php';

/**
 * Line detail belonging to a Focus job.
 */
class FvJobLine extends CommonObjectLine
{
    /** @var string */
    public $element = 'fvjobline';

    /** @var string */
    public $table_element = 'fv_job_line';

    /** @var string */
    public $fk_parent = 'fk_job';

    /** @var array<string, array<string, mixed>> */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 1, 'position' => 3),
        'fk_job' => array('type' => 'integer', 'label' => 'Job', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 5, 'foreignkey' => 'fv_job.rowid'),
        'fk_parent_line' => array('type' => 'integer', 'label' => 'ParentLine', 'enabled' => 1, 'visible' => 0, 'position' => 7, 'foreignkey' => 'fv_job_line.rowid'),
        'fk_batch_line' => array('type' => 'integer', 'label' => 'BatchLine', 'enabled' => 1, 'visible' => 0, 'position' => 8, 'foreignkey' => 'fv_batch_line.rowid'),
        'line_type' => array('type' => 'varchar(32)', 'label' => 'LineType', 'enabled' => 1, 'visible' => 1, 'position' => 10),
        'status' => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'position' => 15, 'default' => 0, 'notnull' => 1),
        'payload_json' => array('type' => 'text', 'label' => 'PayloadJson', 'enabled' => 1, 'visible' => 0, 'position' => 20),
        'response_json' => array('type' => 'text', 'label' => 'ResponseJson', 'enabled' => 1, 'visible' => 0, 'position' => 25),
        'error_message' => array('type' => 'text', 'label' => 'ErrorMessage', 'enabled' => 1, 'visible' => 0, 'position' => 30),
        'order_position' => array('type' => 'integer', 'label' => 'OrderPosition', 'enabled' => 1, 'visible' => 1, 'position' => 35, 'default' => 0),
        'scheduled_for' => array('type' => 'datetime', 'label' => 'DateScheduled', 'enabled' => 1, 'visible' => 0, 'position' => 40),
        'started_at' => array('type' => 'datetime', 'label' => 'DateStart', 'enabled' => 1, 'visible' => 0, 'position' => 45),
        'finished_at' => array('type' => 'datetime', 'label' => 'DateEnd', 'enabled' => 1, 'visible' => 0, 'position' => 50),
        'created_at' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'position' => 500),
        'updated_at' => array('type' => 'datetime', 'label' => 'Tms', 'enabled' => 1, 'visible' => -1, 'position' => 501),
        'fk_user_create' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -1, 'position' => 505, 'foreignkey' => 'user.rowid'),
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
     * {@inheritDoc}
     */
    public function update($user = null, $notrigger = false)
    {
        $this->updated_at = dol_now();

        return $this->updateCommon($user, $notrigger);
    }

    /**
     * {@inheritDoc}
     */
    public function fetch($id, $ref = null)
    {
        return $this->fetchCommon($id, $ref);
    }

    /**
     * {@inheritDoc}
     */
    public function delete($user, $notrigger = false)
    {
        return $this->deleteCommon($user, $notrigger);
    }
}
