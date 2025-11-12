<?php

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once __DIR__ . '/FvBatchLine.class.php';

/**
 * Batch of operations sent to Focus API.
 */
class FvBatch extends CommonObject
{
    /** @var string */
    public $element = 'fvbatch';

    /** @var string */
    public $table_element = 'fv_batch';

    /** @var string */
    public $table_element_line = 'fv_batch_line';

    /** @var string */
    public $fk_element = 'fk_batch';

    /** @var string */
    public $class_element_line = 'FvBatchLine';

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
        'fk_partner_profile' => array('type' => 'integer', 'label' => 'PartnerProfile', 'enabled' => 1, 'visible' => 1, 'position' => 15, 'foreignkey' => 'fv_partner_profile.rowid'),
        'fk_sefaz_profile' => array('type' => 'integer', 'label' => 'SefazProfile', 'enabled' => 1, 'visible' => 0, 'position' => 16, 'foreignkey' => 'fv_sefaz_profile.rowid'),
        'fk_focus_job' => array('type' => 'integer', 'label' => 'FocusJob', 'enabled' => 1, 'visible' => 0, 'position' => 17, 'foreignkey' => 'fv_focus_job.rowid'),
        'batch_type' => array('type' => 'varchar(32)', 'label' => 'BatchType', 'enabled' => 1, 'visible' => 1, 'position' => 20),
        'remote_id' => array('type' => 'varchar(64)', 'label' => 'RemoteId', 'enabled' => 1, 'visible' => 0, 'position' => 25),
        'remote_status' => array('type' => 'varchar(32)', 'label' => 'RemoteStatus', 'enabled' => 1, 'visible' => 0, 'position' => 26),
        'scheduled_for' => array('type' => 'datetime', 'label' => 'DateScheduled', 'enabled' => 1, 'visible' => 1, 'position' => 30),
        'started_at' => array('type' => 'datetime', 'label' => 'DateStart', 'enabled' => 1, 'visible' => 0, 'position' => 32),
        'finished_at' => array('type' => 'datetime', 'label' => 'DateEnd', 'enabled' => 1, 'visible' => 0, 'position' => 34),
        'settings_json' => array('type' => 'text', 'label' => 'SettingsJson', 'enabled' => 1, 'visible' => 0, 'position' => 40),
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
     * Load batch lines from database.
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

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . $this->table_element_line . ' WHERE fk_batch = ' . ((int) $this->id) . ' ORDER BY order_position ASC, rowid ASC';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $line = new FvBatchLine($this->db);
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
     * Append a line to the batch.
     *
     * @param User  $user  User performing the action
     * @param array $data  Field => value map
     * @return int
     */
    public function addLine($user, array $data)
    {
        if (empty($this->id)) {
            $this->error = 'BatchNotPersisted';
            return -1;
        }

        $line = new FvBatchLine($this->db);
        $line->fk_batch = $this->id;
        $line->entity = $this->entity;

        foreach ($data as $field => $value) {
            if (array_key_exists($field, $line->fields)) {
                $line->{$field} = $value;
            }
        }

        return $line->create($user);
    }
}
