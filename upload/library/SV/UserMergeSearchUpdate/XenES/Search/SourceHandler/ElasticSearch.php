<?php

class SV_UserMergeSearchUpdate_XenES_Search_SourceHandler_ElasticSearch extends XFCP_SV_UserMergeSearchUpdate_XenES_Search_SourceHandler_ElasticSearch
{
    public function sv_logSearchResponseError($response, $rollback = false, $extraMessage = '')
    {
        $this->_logSearchResponseError($response, $rollback, $extraMessage);
    }
}