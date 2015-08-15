<?php

class SV_MergeSearchUpdate_Listener
{
    const AddonNameSpace = 'SV_MergeSearchUpdate_';

    public static function install($existingAddOn, array $addOnData, SimpleXMLElement $xml)
    {
        $version = isset($existingAddOn['version_id']) ? $existingAddOn['version_id'] : 0;

        $db = XenForo_Application::getDb();

        $db->query("
            CREATE TABLE IF NOT EXISTS xf_user_merge_queue
            (
                `target` int(10) unsigned NOT NULL,
                `source` int(10) unsigned NOT NULL,
                PRIMARY KEY (`target`, `source`)
            ) ENGINE=InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci
        ");
    }

    public static function uninstall()
    {
        $db = XenForo_Application::getDb();

        $db->query("
            DROP TABLE IF EXISTS xf_collaboration_users_post;
        ");
        return true;
    }

    public static function load_class($class, array &$extend)
    {
        $extend[] = self::AddonNameSpace.$class;
    }
}