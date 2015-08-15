<?php

class SV_MergeSearchUpdate_Deferred_SearchIndex extends XenForo_Deferred_Abstract
{
    public function execute(array $deferred, array $data, $targetRunTime, &$status)
    {
        $haveMore = true;

        $s = microtime(true);
        while($haveMore)
        {
            $haveMore = XenForo_Model::create('XenForo_Model_User')->updateSearchIndexForMergedUsers();

            $runTime = microtime(true) - $s;
            if ($targetRunTime && $runTime > $targetRunTime)
            {
                $outOfTime = true;
                break;
            }
        }

        return $haveMore;
    }

    public function canCancel()
    {
        return false;
    }
}