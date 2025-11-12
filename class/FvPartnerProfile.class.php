<?php

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Partner profile configuration for Focus integration.
 */
class FvPartnerProfile extends CommonObject
{
    /** @var string */
    public $element = 'fvpartnerprofile';

    /** @var string */
    public $table_element = 'fv_partner_profile';

    /** @var int */
    public $ismultientitymanaged = 1;

    /** @var int */
    public $isextrafieldmanaged = 1;

    /** @var array<string, array<string, mixed>> */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 1, 'position' => 5),
        'status' => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'default' => 1, 'notnull' => 1),
        'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'visible' => 1, 'index' => 1, 'position' => 15),
        'fk_soc' => array('type' => 'integer', 'label' => 'Thirdparty', 'enabled' => 1, 'visible' => 1, 'position' => 20, 'foreignkey' => 'societe.rowid'),
        'settings_json' => array('type' => 'text', 'label' => 'SettingsJson', 'enabled' => 1, 'visible' => 0, 'position' => 25),
        'remote_id' => array('type' => 'varchar(64)', 'label' => 'RemoteId', 'enabled' => 1, 'visible' => 0, 'position' => 30),
        'remote_sync_date' => array('type' => 'datetime', 'label' => 'RemoteSyncDate', 'enabled' => 1, 'visible' => 0, 'position' => 35),
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
    public function fetch($id, $ref = null)
    {
        return $this->fetchCommon($id, $ref);
    }
}
