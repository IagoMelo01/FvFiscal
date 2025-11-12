<?php
/* Copyright (C) 2025           SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Class representing an SEFAZ profile configuration.
 */
class FvSefazProfile extends CommonObject
{
    /** @var string Module element type */
    public $element = 'fvsefazprofile';

    /** @var string Database table without prefix */
    public $table_element = 'fv_sefaz_profile';

    /** @var int */
    public $ismultientitymanaged = 1;

    /** @var int */
    public $isextrafieldmanaged = 1;

    /** @var array<string, array<string, mixed>> */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 1, 'position' => 5),
        'status' => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'notnull' => 1, 'default' => 0),
        'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'visible' => 1, 'position' => 15, 'notnull' => 1, 'index' => 1),
        'name' => array('type' => 'varchar(255)', 'label' => 'Name', 'enabled' => 1, 'visible' => 1, 'position' => 20, 'notnull' => 1),
        'environment' => array('type' => 'varchar(32)', 'label' => 'Environment', 'enabled' => 1, 'visible' => 1, 'position' => 25, 'notnull' => 1, 'default' => 'production'),
        'email' => array('type' => 'varchar(255)', 'label' => 'Email', 'enabled' => 1, 'visible' => 1, 'position' => 30),
        'certificate_path' => array('type' => 'varchar(255)', 'label' => 'CertificatePath', 'enabled' => 1, 'visible' => 1, 'position' => 35),
        'certificate_password' => array('type' => 'varchar(128)', 'label' => 'CertificatePassword', 'enabled' => 1, 'visible' => 0, 'position' => 40),
        'certificate_expire_at' => array('type' => 'datetime', 'label' => 'CertificateExpireAt', 'enabled' => 1, 'visible' => 1, 'position' => 45),
        'tax_regime' => array('type' => 'varchar(64)', 'label' => 'TaxRegime', 'enabled' => 1, 'visible' => 1, 'position' => 50),
        'tax_regime_detail' => array('type' => 'varchar(64)', 'label' => 'TaxRegimeDetail', 'enabled' => 1, 'visible' => 1, 'position' => 55),
        'csc_id' => array('type' => 'varchar(32)', 'label' => 'CSCId', 'enabled' => 1, 'visible' => 0, 'position' => 60),
        'csc_token' => array('type' => 'varchar(80)', 'label' => 'CSCToken', 'enabled' => 1, 'visible' => 0, 'position' => 65),
        'webhook_secret' => array('type' => 'varchar(80)', 'label' => 'WebhookSecret', 'enabled' => 1, 'visible' => 0, 'position' => 70),
        'created_at' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'position' => 500),
        'updated_at' => array('type' => 'datetime', 'label' => 'Tms', 'enabled' => 1, 'visible' => -1, 'position' => 501),
        'fk_user_create' => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -1, 'position' => 505, 'foreignkey' => 'user.rowid'),
        'fk_user_modif' => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => 1, 'visible' => -1, 'position' => 506, 'foreignkey' => 'user.rowid'),
        'note_public' => array('type' => 'text', 'label' => 'NotePublic', 'enabled' => 1, 'visible' => 0, 'position' => 510),
        'note_private' => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => 1, 'visible' => 0, 'position' => 515),
    );

    /** @var array<int, mixed> */
    public $childtables = array('fv_nfe_out', 'fv_mdfe', 'fv_focus_job', 'fv_batch_export');

    /** @var string */
    public $picto = 'generic';

    /** @var string[] */
    public $statuts = array(0 => 'Draft', 1 => 'Enabled', 2 => 'Archived');

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
}
