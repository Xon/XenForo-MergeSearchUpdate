<?php

class SV_UserMergeSearchUpdate_XenForo_Model_User extends XFCP_SV_UserMergeSearchUpdate_XenForo_Model_User
{
    protected $allowUpdateSearchOnMerge = true;

    public function queueUserSearchUpdate($oldUserId, $newUserId)
    {
        if ($newUserId != $oldUserId)
        {
            return;
        }
        $this->_getDb()->query('
            insert ignore xf_sv_user_merge_queue (target, source) values (?,?)
        ', array($newUserId, $oldUserId));
        // trigger re-indexing.
        XenForo_Application::defer('SV_UserMergeSearchUpdate_Deferred_SearchIndex', array(), 'user_merge');
    }

    public function mergeUsers(array $target, array $source)
    {
        $result = parent::mergeUsers($target, $source);
        if ($result && $this->allowUpdateSearchOnMerge)
        {
            $this->queueUserSearchUpdate($source['user_id'], $target['user_id']);
        }

        return $result;
    }

    public function updateSearchIndexForMergedUsers($limit = 1000)
    {
        $db = $this->_getDb();

        $user = $db->fetchRow('
            SELECT *
            FROM xf_sv_user_merge_queue
            ORDER BY queue_id
            LIMIT 1
        ');
        if (empty($user))
        {
            return false;
        }

        if (XenForo_Application::getOptions()->enableElasticsearch)
        {
            $class = XenForo_Application::resolveDynamicClass('XenES_Search_SourceHandler_ElasticSearch');
            if (!class_exists($class))
            {
                return false;
            }
            /** @var SV_UserMergeSearchUpdate_XenES_Search_SourceHandler_ElasticSearch $sourceHandler */
            $sourceHandler = new $class();

            // query Elastic Search for content with a matching UserId
            $dsl = array(
                'from' => 0,
                'size' => $limit,
            );
            $version = XenES_Api::version();
            if ($version < 5)
            {
                $dsl['query']['filtered']['filter']['bool']['must'] = array('term' => array('user' => $user['source']));

            }
            else
            {
                $dsl['query']['bool']['must'] = array('term' => array('user' => $user['source']));

            }

            $esApi = XenES_Api::getInstance();
            $indexName = $esApi->getIndex();


            $response = XenES_Api::search($indexName, $dsl);
            if (!$response || !isset($response->hits, $response->hits->hits))
            {
                $sourceHandler->sv_logSearchResponseError($response);
                return false;
            }
            /** @noinspection PhpUndefinedFieldInspection */
            $totalHits = $response->hits->total;
            /** @noinspection PhpUndefinedFieldInspection */
            $hits = $response->hits->hits;

            // Require re-indexing, as an inplace update can not be done.
            // Elastic Search requires the entire source document to be stored, which XF doesn't do.
            $haveMore = $totalHits >= $limit;
            if ($totalHits > 0)
            {
                /** @var XenForo_Model_Search $searchModel */
                $searchModel = XenForo_Model::create('XenForo_Model_Search');
                $searchContentTypes = $searchModel->getSearchContentTypes();
                $indexer = new XenForo_Search_Indexer();
                $indexer->setIsRebuild(true);

                // collect into per content type batches to pass to the various search handlers
                $ContentIdBatch = array();
                foreach ($hits as &$hit)
                {
                    $contentType = $hit->_type;
                    if (!isset($ContentIdBatch[$contentType]))
                    {
                        $ContentIdBatch[$contentType] = array();
                    }
                    $ContentIdBatch[$contentType][] = $hit->_id;
                }

                foreach($ContentIdBatch as $contentType => &$ContentIds)
                {
                    if (!isset($searchContentTypes[$contentType]))
                    {
                        continue;
                    }

                    $searchHandler = $searchContentTypes[$contentType];
                    if (class_exists($searchHandler))
                    {
                        $handler = XenForo_Search_DataHandler_Abstract::create($searchHandler);
                        $handler->quickIndex($indexer, $ContentIds);
                    }
                }
                // flush out the rebuild set
                $indexer->finalizeRebuildSet();
            }
        }
        else
        {
            // update MySQL search index
            $db->query('
                UPDATE xf_search_index
                set user_id = ?
                WHERE user_id = ?
            ', array($user['target'], $user['source']));
            $haveMore = false;
        }

        if ($haveMore)
        {
            return $haveMore;
        }

        $db->query('
            DELETE
            FROM xf_sv_user_merge_queue
            WHERE target = ? and source = ?
        ', array($user['target'], $user['source']));

        $haveMore = $db->fetchOne('
            SELECT count(*)
            FROM xf_sv_user_merge_queue
        ') != 0;
        return $haveMore;
    }
}

if (false)
{
    class XFCP_SV_UserMergeSearchUpdate_XenForo_Model_User extends XenForo_Model_User {}
}