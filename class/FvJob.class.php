<?php

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once __DIR__ . '/FvJobLine.class.php';

/**
 * Focus job representation for Dolibarr tracking.
 */
class FvJob extends CommonObject
{
    /** @var string */
    public $element = 'fvjob';

    /** @var string */
    public $table_element = 'fv_job';

    /** @var string */
    public $table_element_line = 'fv_job_line';

    /** @var string */
    public $fk_element = 'fk_job';

    /** @var string */
    public $class_element_line = 'FvJobLine';

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
        'status' => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'default' => 0, 'notnull' => 1),
        'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'visible' => 1, 'index' => 1, 'position' => 12),
        'fk_batch' => array('type' => 'integer', 'label' => 'Batch', 'enabled' => 1, 'visible' => 1, 'position' => 15, 'foreignkey' => 'fv_batch.rowid'),
        'fk_batch_line' => array('type' => 'integer', 'label' => 'BatchLine', 'enabled' => 1, 'visible' => 0, 'position' => 16, 'foreignkey' => 'fv_batch_line.rowid'),
        'fk_focus_job' => array('type' => 'integer', 'label' => 'FocusJob', 'enabled' => 1, 'visible' => 0, 'position' => 17, 'foreignkey' => 'fv_focus_job.rowid'),
        'job_type' => array('type' => 'varchar(32)', 'label' => 'JobType', 'enabled' => 1, 'visible' => 1, 'position' => 20),
        'job_payload' => array('type' => 'text', 'label' => 'JobPayload', 'enabled' => 1, 'visible' => 0, 'position' => 25),
        'job_response' => array('type' => 'text', 'label' => 'JobResponse', 'enabled' => 1, 'visible' => 0, 'position' => 30),
        'error_message' => array('type' => 'text', 'label' => 'ErrorMessage', 'enabled' => 1, 'visible' => 0, 'position' => 35),
        'scheduled_for' => array('type' => 'datetime', 'label' => 'DateScheduled', 'enabled' => 1, 'visible' => 1, 'position' => 40),
        'started_at' => array('type' => 'datetime', 'label' => 'DateStart', 'enabled' => 1, 'visible' => 0, 'position' => 45),
        'finished_at' => array('type' => 'datetime', 'label' => 'DateEnd', 'enabled' => 1, 'visible' => 0, 'position' => 50),
        'attempt_count' => array('type' => 'integer', 'label' => 'AttemptCount', 'enabled' => 1, 'visible' => 0, 'position' => 55, 'default' => 0),
        'remote_id' => array('type' => 'varchar(64)', 'label' => 'RemoteId', 'enabled' => 1, 'visible' => 0, 'position' => 60),
        'remote_status' => array('type' => 'varchar(32)', 'label' => 'RemoteStatus', 'enabled' => 1, 'visible' => 0, 'position' => 65),
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
     * Fetch job lines from database.
     *
     * @param bool $force Force reload
     * @return int
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

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . $this->table_element_line . ' WHERE fk_job = ' . ((int) $this->id) . ' ORDER BY order_position ASC, rowid ASC';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $line = new FvJobLine($this->db);
            if ($line->fetch($obj->rowid) >= 0) {
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
     * Attach a line to the job.
     *
     * @param User  $user  Current user
     * @param array $data  Line data
     * @return int
     */
    public function addLine($user, array $data)
    {
        if (empty($this->id)) {
            $this->error = 'JobNotPersisted';
            return -1;
        }

        $line = new FvJobLine($this->db);
        $line->fk_job = $this->id;
        $line->entity = $this->entity;

        foreach ($data as $field => $value) {
            if (array_key_exists($field, $line->fields)) {
                $line->{$field} = $value;
            }
        }

        return $line->create($user);
    }
}
