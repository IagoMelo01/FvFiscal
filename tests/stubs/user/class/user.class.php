<?php
class User
{
    /** @var mixed */
    public $db;

    /** @var int */
    public $id = 1;

    /** @var int */
    public $admin = 1;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function fetch($id, $login = '', $pass = '', $email = '', $employee = '', $field = '')
    {
        $this->id = $id ?: 1;

        return 1;
    }
}
