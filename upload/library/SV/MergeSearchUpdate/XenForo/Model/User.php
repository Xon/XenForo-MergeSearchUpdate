<?php

class SV_MergeSearchUpdate_XenForo_Model_User extends XFCP_SV_MergeSearchUpdate_XenForo_Model_User
{
    public function mergeUsers(array $target, array $source)
    {
        $result = parent::mergeUsers($target, $source);
        if ($result && $target['user_id'] != $source['user_id'])
        {
            $this->_getDb()->query('
                insert ignore xf_user_merge_queue (target, source) values (?,?)
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

        $finished = false;

        $options = XenForo_Application::getOptions();
        if ($options->enableElasticsearch && class_exists('XenES_Api'))
        {
            // update Elastic Search search index.
            $esApi = XenES_Api::getInstance();
            $indexName = $esApi->getIndex();

            $class = XenForo_Application::resolveDynamicClass('XenES_Search_SourceHandler_ElasticSearch');
            $sourceHandler = new $class();

            $dsl = '{
    "from" : 0, "size" : "' . intval($limit). '",
    "query": {
        "filtered": {
            "filter": {
                "bool" : {
                    "must" : [
                        {
                            "term" : {"user" : "' . $user['source'] . '"}
                        }
                    ]
                }
            }
        }
    }
}
';

            $response = XenES_Api::search($indexName, $dsl);
            if (!$response || !isset($response->hits, $response->hits->hits))
            {
                $sourceHandler->sv_logSearchResponseError($response);
                return true;
            }

            $items = array();
            foreach ($response->hits->hits as &$hit)
            {
                $items[] = json_encode(array('index' => array(
                    '_index' => $hit->_index,
                    '_type' => $hit->_type,
                    '_id' => $hit->_id
                ))) . "\n" . json_encode(array(
                    'doc' => array(
                        'user' => $user['target']
                    )
                ));
            }

            $response = self::getInstance()->call(Zend_Http_Client::POST,
                '_bulk',
                implode("\n", $items) . "\n"
            );

            if (!$response || !isset($response->took))
            {
                $sourceHandler->sv_logSearchResponseError($response);
                return true;
            }

            $response = $this->es_updateBulk($indexName, $contentType, $results);

            if ($response->hits->total < $limit)
            {
                $sourceHandler->sv_logSearchResponseError($response);
                $finished = true;
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
            $finished = true;
        }

        if ($finished)
        {
            $db->query('
                DELETE
                FROM xf_sv_user_merge_queue
                WHERE target = ? source = ?
            ', array($user['target'], $user['source']));
        }

        $haveMore = $db->fetchOne('
            SELECT count(*)
            FROM xf_sv_user_merge_queue
        ') != 0;
        return $haveMore;
    }

    public function es_updateBulk($indexName, $contentType, array $contentData)
    {
        $items = array();

        foreach ($contentData AS $contentId => $content)
        {
            $items[] = json_encode(array('index' => array(
                '_index' => $indexName,
                '_type' => $contentType,
                '_id' => $contentId
            ))) . "\n" . json_encode($content);
        }

        return self::getInstance()->call(Zend_Http_Client::POST,
            '_bulk',
            implode("\n", $items) . "\n"
        );
    }
}