<?php

class SV_UserMergeSearchUpdate_Listener
{
    public static function load_class($class, array &$extend)
    {
        $extend[] = 'SV_UserMergeSearchUpdate_'.$class;
    }
}