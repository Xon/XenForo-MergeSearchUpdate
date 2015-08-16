<?php

class SV_UserMergeSearchUpdate_Listener
{
    const AddonNameSpace = 'SV_UserMergeSearchUpdate_';

    public static function install($existingAddOn, array $addOnData, SimpleXMLElement $xml)
    {
        $version = isset($existingAddOn['version_id']) ? $existingAddOn['version_id'] : 0;

        $db = XenForo_Application::getDb();

        $db->query("
            CREATE TABLE IF NOT EXISTS xf_sv_user_merge_queue
            (
                `queue_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `target` int(10) unsigned NOT NULL,
                `source` int(10) unsigned NOT NULL,
                PRIMARY KEY (`queue_id`),
                UNIQUE KEY `change_set` (`target`,`source`)
            ) ENGINE=InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci
        ");
    }

    public static function uninstall()
    {
        $db = XenForo_Application::getDb();

        $db->query("
            DROP TABLE IF EXISTS xf_sv_user_merge_queue;
        ");
        return true;
    }

    public static function load_class($class, array &$extend)
    {
        $extend[] = self::AddonNameSpace.$class;
    }
}