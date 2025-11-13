<?php
function price2num($value, $type = 'MT')
{
    if ($value === null || $value === '') {
        return 0;
    }

    return (float) $value;
}
