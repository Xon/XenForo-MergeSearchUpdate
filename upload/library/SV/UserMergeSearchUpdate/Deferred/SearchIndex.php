<?php

class SV_UserMergeSearchUpdate_Deferred_SearchIndex extends XenForo_Deferred_Abstract
{
    public function execute(array $deferred, array $data, $targetRunTime, &$status)
    {
        $s = microtime(true);
        $userModel = XenForo_Model::create('XenForo_Model_User');
        $haveMore = is_callable(array($userModel, 'updateSearchIndexForMergedUsers'));
        while($haveMore)
        {
            $haveMore = $userModel->updateSearchIndexForMergedUsers();

            $runTime = microtime(true) - $s;
            if ($targetRunTime && $runTime > $targetRunTime)
            {
                break;
            }
        }

        if (!$haveMore)
        {
            return false;
        }

        return array();
    }

    public function canCancel()
    {
        return false;
    }
}