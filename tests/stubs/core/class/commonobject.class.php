<?php
class CommonObject
{
    /** @var mixed */
    public $db;

    /** @var int */
    public $id;

    /** @var int */
    public $rowid;

    /** @var array */
    public $errors = array();

    public function __construct($db = null)
    {
        $this->db = $db;
    }

    protected function createCommon($user = null, $notrigger = false)
    {
        if (empty($this->id)) {
            $this->id = rand(1000, 9999);
        }
        $this->rowid = $this->id;

        return $this->id;
    }

    protected function updateCommon($user = null, $notrigger = false)
    {
        return 1;
    }

    protected function fetchCommon($id, $ref = null)
    {
        $this->id = (int) $id;
        $this->rowid = (int) $id;

        return 1;
    }
}
