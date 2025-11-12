<?php
/* Copyright (C) 2025           SuperAdmin */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobjectline.class.php';

/**
 * Line representation for outbound NF-e documents.
 */
class FvNfeOutLine extends CommonObjectLine
{
    /** @var string */
    public $element = 'fvnfeoutline';

    /** @var string */
    public $table_element = 'fv_nfe_out_line';

    /** @var string */
    public $fk_parent = 'fk_nfeout';

    /** @var array<string, array<string, mixed>> */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'index' => 1, 'position' => 1),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 1, 'position' => 3),
        'fk_nfeout' => array('type' => 'integer', 'label' => 'FkNfeOut', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 5, 'foreignkey' => 'fv_nfe_out.rowid'),
        'fk_product' => array('type' => 'integer', 'label' => 'Product', 'enabled' => 1, 'visible' => 1, 'position' => 10, 'foreignkey' => 'product.rowid'),
        'fk_unit' => array('type' => 'integer', 'label' => 'Unit', 'enabled' => 1, 'visible' => 0, 'position' => 11, 'foreignkey' => 'c_units.rowid'),
        'rang' => array('type' => 'integer', 'label' => 'LineRank', 'enabled' => 1, 'visible' => -1, 'position' => 12),
        'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'visible' => 1, 'position' => 15),
        'description' => array('type' => 'text', 'label' => 'Description', 'enabled' => 1, 'visible' => 1, 'position' => 20),
        'qty' => array('type' => 'double(24,8)', 'label' => 'Qty', 'enabled' => 1, 'visible' => 1, 'position' => 30, 'notnull' => 1, 'default' => 0),
        'unit_price' => array('type' => 'double(24,8)', 'label' => 'UnitPrice', 'enabled' => 1, 'visible' => 1, 'position' => 35, 'default' => 0),
        'discount_percent' => array('type' => 'double(10,4)', 'label' => 'DiscountPercent', 'enabled' => 1, 'visible' => 0, 'position' => 40, 'default' => 0),
        'discount_amount' => array('type' => 'double(24,8)', 'label' => 'DiscountAmount', 'enabled' => 1, 'visible' => 0, 'position' => 45, 'default' => 0),
        'total_ht' => array('type' => 'double(24,8)', 'label' => 'TotalHT', 'enabled' => 1, 'visible' => 1, 'position' => 50, 'default' => 0),
        'total_taxes' => array('type' => 'double(24,8)', 'label' => 'TotalTaxes', 'enabled' => 1, 'visible' => 1, 'position' => 55, 'default' => 0),
        'total_ttc' => array('type' => 'double(24,8)', 'label' => 'TotalTTC', 'enabled' => 1, 'visible' => 1, 'position' => 60, 'default' => 0),
        'ncm' => array('type' => 'varchar(16)', 'label' => 'NCM', 'enabled' => 1, 'visible' => 1, 'position' => 65),
        'cfop' => array('type' => 'varchar(10)', 'label' => 'CFOP', 'enabled' => 1, 'visible' => 1, 'position' => 70),
        'cest' => array('type' => 'varchar(10)', 'label' => 'CEST', 'enabled' => 1, 'visible' => 0, 'position' => 75),
        'icms_rate' => array('type' => 'double(10,4)', 'label' => 'ICMSRate', 'enabled' => 1, 'visible' => 0, 'position' => 80, 'default' => 0),
        'icms_amount' => array('type' => 'double(24,8)', 'label' => 'ICMSAmount', 'enabled' => 1, 'visible' => 0, 'position' => 85, 'default' => 0),
        'ipi_rate' => array('type' => 'double(10,4)', 'label' => 'IPIRate', 'enabled' => 1, 'visible' => 0, 'position' => 90, 'default' => 0),
        'ipi_amount' => array('type' => 'double(24,8)', 'label' => 'IPIAmount', 'enabled' => 1, 'visible' => 0, 'position' => 95, 'default' => 0),
        'pis_rate' => array('type' => 'double(10,4)', 'label' => 'PISRate', 'enabled' => 1, 'visible' => 0, 'position' => 100, 'default' => 0),
        'pis_amount' => array('type' => 'double(24,8)', 'label' => 'PISAmount', 'enabled' => 1, 'visible' => 0, 'position' => 105, 'default' => 0),
        'cofins_rate' => array('type' => 'double(10,4)', 'label' => 'COFINSRate', 'enabled' => 1, 'visible' => 0, 'position' => 110, 'default' => 0),
        'cofins_amount' => array('type' => 'double(24,8)', 'label' => 'COFINSAmount', 'enabled' => 1, 'visible' => 0, 'position' => 115, 'default' => 0),
        'issqn_rate' => array('type' => 'double(10,4)', 'label' => 'ISSQNRate', 'enabled' => 1, 'visible' => 0, 'position' => 120, 'default' => 0),
        'issqn_amount' => array('type' => 'double(24,8)', 'label' => 'ISSQNAmount', 'enabled' => 1, 'visible' => 0, 'position' => 125, 'default' => 0),
        'created_at' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -1, 'position' => 500),
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
    public function fetch($id, $ref = null)
    {
        return $this->fetchCommon($id, $ref);
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
    public function delete($user, $notrigger = false)
    {
        return $this->deleteCommon($user, $notrigger);
    }
}
