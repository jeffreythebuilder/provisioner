<?php
namespace FreePBX\modules\Provisioner;

// Called only by the official install/upgrade hooks. Runtime checks never repair
// schema: an unapplied migration must stop the push before any notification.
final class PushJobSchema {
    public static function payloadType($db) {
        $stmt = $db->query("SHOW COLUMNS FROM provisioner_push_jobs LIKE 'payload'");
        if (!$stmt) throw new \RuntimeException('Could not inspect push job storage.');
        $column = $stmt->fetch(\PDO::FETCH_ASSOC);
        return strtolower($column['Type'] ?? '');
    }

    public static function assertReady($db) {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') return;
        if (!in_array(self::payloadType($db), ['mediumtext', 'longtext'], true)) {
            throw new \RuntimeException('Push job storage requires an official Provisioner Module Admin install/upgrade before pushing phones.');
        }
    }

    public static function install($db) {
        $oldMode = $db->getAttribute(\PDO::ATTR_ERRMODE);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS provisioner_push_jobs (id VARCHAR(32) PRIMARY KEY, status VARCHAR(20) NOT NULL, payload MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, error TEXT DEFAULT NULL)");
            if (!in_array(self::payloadType($db), ['mediumtext', 'longtext'], true)) {
                $db->exec('ALTER TABLE provisioner_push_jobs MODIFY payload MEDIUMTEXT NOT NULL');
            }
            self::assertReady($db);
        } finally {
            $db->setAttribute(\PDO::ATTR_ERRMODE, $oldMode);
        }
    }
}
