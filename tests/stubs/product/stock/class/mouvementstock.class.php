<?php
class MouvementStock
{
    public $origin;
    public $origin_type;

    public function __construct($db)
    {
    }

    public function _create($user, $productId, $warehouseId, $qty, $label)
    {
        return $productId > 0 ? 1 : -1;
    }
}
