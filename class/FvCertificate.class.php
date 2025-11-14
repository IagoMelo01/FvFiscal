<?php
/* Copyright (C) 2025           SuperAdmin */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Store and manage PKCS#12 certificates used by fiscal workflows.
 */
class FvCertificate extends CommonObject
{
    /** @var string */
    public $element = 'fvcertificate';

    /** @var string */
    public $table_element = 'fv_certificate';

    /** @var int */
    public $ismultientitymanaged = 1;

    /** @var int */
    public $isextrafieldmanaged = 1;

    /** @var array<string, array<string, mixed>> */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 1, 'position' => 5),
        'status' => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => 0, 'position' => 10),
        'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'index' => 1, 'position' => 12),
        'label' => array('type' => 'varchar(255)', 'label' => 'Label', 'enabled' => 1, 'visible' => 1, 'position' => 13),
        'certificate_path' => array('type' => 'varchar(255)', 'label' => 'CertificatePath', 'enabled' => 1, 'visible' => 0, 'position' => 20),
        'certificate_password' => array('type' => 'varchar(255)', 'label' => 'CertificatePassword', 'enabled' => 0, 'visible' => -1, 'position' => 21),
        'certificate_expire_at' => array('type' => 'datetime', 'label' => 'DateEnd', 'enabled' => 1, 'visible' => 1, 'position' => 25),
        'metadata_json' => array('type' => 'text', 'label' => 'MetadataJson', 'enabled' => 1, 'visible' => 0, 'position' => 30),
        'note_public' => array('type' => 'text', 'label' => 'NotePublic', 'enabled' => 1, 'visible' => 0, 'position' => 40),
        'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => 1, 'visible' => 0, 'position' => 45),
        'created_at' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'position' => 500),
        'updated_at' => array('type' => 'datetime', 'label' => 'Tms', 'enabled' => 1, 'visible' => -1, 'position' => 501),
        'fk_user_create' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -1, 'position' => 505, 'foreignkey' => 'user.rowid'),
        'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => 1, 'visible' => -1, 'position' => 506, 'foreignkey' => 'user.rowid'),
    );

    /** @var string */
    public $picto = 'document';

    /** @var string[] */
    public $statuts = array(0 => 'Draft', 1 => 'Enabled', 2 => 'Archived');

    /**
     * {@inheritDoc}
     */
    public function create($user = null, $notrigger = false)
    {
        global $conf;

        if (empty($this->entity)) {
            $this->entity = $conf->entity;
        }
        if (empty($this->created_at)) {
            $this->created_at = dol_now();
        }
        if ($user) {
            $this->fk_user_create = $user->id;
        }

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
