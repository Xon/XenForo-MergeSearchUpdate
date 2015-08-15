<?php

class SV_MergeSearchUpdate_XenForo_Model_User extends XFCP_SV_MergeSearchUpdate_XenForo_Model_User
{
    public function mergeUsers(array $target, array $source)
    {
        $result = parent::mergeUsers($target, $source);
        if ($result && $target['user_id'] != $source['user_id'])
        {
            $this->_getDb()->query('
                insert ignore xf_sv_user_merge_queue (target, source) values (?,?)
            ', array($target['user_id'], $source['user_id']));
            // trigger re-indexing.
            XenForo_Application::defer('SV_MergeSearchUpdate_Deferred_SearchIndex', array(), 'user_merge');
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

        if (XenForo_Application::getOptions()->enableElasticsearch && class_exists('XenES_Api'))
        {
            // query Elastic Search for content with a matching UserId
            $dsl = array(
                'from' => 0,
                'size' => $limit,
                'query' => array(
                    'filtered' => array(
                        'filter' => array(
                            'bool' => array(
                                'must' => array(
                                    array('term' => array('user' => $user['source']))
                                )
                            )
                        )
                    )
                )
            );

            $esApi = XenES_Api::getInstance();
            $indexName = $esApi->getIndex();
            $class = XenForo_Application::resolveDynamicClass('XenES_Search_SourceHandler_ElasticSearch');
            $sourceHandler = new $class();

            $response = XenES_Api::search($indexName, $dsl);
            if (!$response || !isset($response->hits, $response->hits->hits))
            {
                $sourceHandler->sv_logSearchResponseError($response);
                return false;
            }

            // Require re-indexing, as an inplace update can not be done.
            // Elastic Search requires the entire source document to be stored, which XF doesn't do.
            $haveMore = $response->hits->total >= $limit;
            if ($response->hits->total > 0)
            {
                $searchModel = XenForo_Model::create('XenForo_Model_Search');
                $searchContentTypes = $searchModel->getSearchContentTypes();
                $indexer = new XenForo_Search_Indexer();
                $indexer->setIsRebuild(true);

                // collect into per content type batches to pass to the various search handlers
                $ContentIdBatch = array();
                foreach ($response->hits->hits as &$hit)
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