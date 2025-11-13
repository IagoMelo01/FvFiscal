<?php
class Societe extends CommonObject
{
    public static $sequence = 0;

    public $entity = 1;
    public $status = 1;
    public $client = 0;
    public $fournisseur = 0;
    public $name = '';
    public $nom = '';
    public $code_fournisseur = '';
    public $note_private = '';
    public $address = '';
    public $zip = '';
    public $town = '';
    public $state_code = '';
    public $country_code = '';
    public $email = '';
    public $tva_intra = '';
    public $idprof1 = '';
    public $idprof2 = '';
    public $error = '';

    public function __construct($db)
    {
        parent::__construct($db);
    }

    public function create($user)
    {
        if (empty($this->name) && empty($this->nom)) {
            $this->error = 'Name is required';
            return -1;
        }

        self::$sequence++;
        $this->id = self::$sequence;
        $this->rowid = $this->id;

        return $this->id;
    }

    public function getAvailableCode($mode)
    {
        return strtoupper(substr($mode, 0, 3)) . str_pad((string) (self::$sequence + 1), 4, '0', STR_PAD_LEFT);
    }
}
