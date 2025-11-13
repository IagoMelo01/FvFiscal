<?php
class FactureFournisseur
{
    public $socid;
    public $entity;
    public $date;
    public $ref_supplier;
    public $libelle;
    public $error = '';
    public $lines = array();

    public function __construct($db)
    {
    }

    public function create($user)
    {
        if (empty($this->socid)) {
            $this->error = 'Missing thirdparty';
            return -1;
        }

        return 1;
    }

    public function addline($desc, $pu_ht, $qty)
    {
        $this->lines[] = array('desc' => $desc, 'pu_ht' => $pu_ht, 'qty' => $qty);

        return 1;
    }
}
