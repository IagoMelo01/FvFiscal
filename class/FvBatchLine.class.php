<?php

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobjectline.class.php';

/**
 * Line item tracked inside a Focus batch.
 */
class FvBatchLine extends CommonObjectLine
{
    /** @var string */
    public $element = 'fvbatchline';

    /** @var string */
    public $table_element = 'fv_batch_line';

    /** @var string */
    public $fk_parent = 'fk_batch';

    /** @var array<string, array<string, mixed>> */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 1, 'position' => 5),
        'fk_batch' => array('type' => 'integer', 'label' => 'Batch', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 10, 'foreignkey' => 'fv_batch.rowid'),
        'fk_parent_line' => array('type' => 'integer', 'label' => 'ParentLine', 'enabled' => 1, 'visible' => 0, 'position' => 12, 'foreignkey' => 'fv_batch_line.rowid'),
        'fk_origin' => array('type' => 'integer', 'label' => 'OriginId', 'enabled' => 1, 'visible' => 0, 'position' => 15),
        'fk_origin_type' => array('type' => 'varchar(64)', 'label' => 'OriginType', 'enabled' => 1, 'visible' => 0, 'position' => 16),
        'line_type' => array('type' => 'varchar(32)', 'label' => 'LineType', 'enabled' => 1, 'visible' => 1, 'position' => 20),
        'status' => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'position' => 25, 'default' => 0, 'notnull' => 1),
        'order_position' => array('type' => 'integer', 'label' => 'OrderPosition', 'enabled' => 1, 'visible' => 1, 'position' => 30, 'default' => 0),
        'payload_json' => array('type' => 'text', 'label' => 'PayloadJson', 'enabled' => 1, 'visible' => 0, 'position' => 35),
        'response_json' => array('type' => 'text', 'label' => 'ResponseJson', 'enabled' => 1, 'visible' => 0, 'position' => 40),
        'error_message' => array('type' => 'text', 'label' => 'ErrorMessage', 'enabled' => 1, 'visible' => 0, 'position' => 45),
        'scheduled_for' => array('type' => 'datetime', 'label' => 'DateScheduled', 'enabled' => 1, 'visible' => 0, 'position' => 46),
        'started_at' => array('type' => 'datetime', 'label' => 'DateStart', 'enabled' => 1, 'visible' => 0, 'position' => 47),
        'finished_at' => array('type' => 'datetime', 'label' => 'DateEnd', 'enabled' => 1, 'visible' => 0, 'position' => 48),
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
