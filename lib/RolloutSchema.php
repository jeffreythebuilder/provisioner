<?php
namespace FreePBX\modules\Provisioner;

final class RolloutSchema {
    const REVISION = 2;
    public static function install($db) {
        $old = $db->getAttribute(\PDO::ATTR_ERRMODE);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS provisioner_batches (id VARCHAR(32) PRIMARY KEY, kind VARCHAR(20) NOT NULL, parent_id VARCHAR(32), status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, finished_at DATETIME)");
            $db->exec("CREATE TABLE IF NOT EXISTS provisioner_batch_items (batch_id VARCHAR(32) NOT NULL, mac VARCHAR(12) NOT NULL, job_id VARCHAR(32), data TEXT NOT NULL, PRIMARY KEY (batch_id,mac))");
            $db->exec("CREATE TABLE IF NOT EXISTS provisioner_rollouts (id VARCHAR(32) PRIMARY KEY, status VARCHAR(20) NOT NULL, fingerprint VARCHAR(64) NOT NULL, request_key VARCHAR(64) UNIQUE, waves MEDIUMTEXT NOT NULL, wave_cursor INTEGER NOT NULL DEFAULT 0, heartbeat INTEGER NOT NULL DEFAULT 0, expires INTEGER NOT NULL DEFAULT 0, token VARCHAR(32), created_at DATETIME NOT NULL, error TEXT, summary TEXT)");
            $db->exec('CREATE TABLE IF NOT EXISTS provisioner_rollout_owners (mac VARCHAR(12) PRIMARY KEY, rollout_id VARCHAR(32) NOT NULL)');
            $db->exec('CREATE TABLE IF NOT EXISTS provisioner_rollout_control (id INTEGER PRIMARY KEY, token VARCHAR(32), expires INTEGER NOT NULL DEFAULT 0, schema_revision INTEGER NOT NULL DEFAULT 0)');
            // MariaDB 5.5 requires FROM DUAL for a constant SELECT with WHERE.
            // SQLite has no DUAL table; keep its isolated fixture path portable.
            $from = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FROM DUAL';
            $db->exec('INSERT INTO provisioner_rollout_control (id,expires) SELECT 1,0'.$from.' WHERE NOT EXISTS (SELECT 1 FROM provisioner_rollout_control WHERE id=1)');
            $controlColumns = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? array_column($db->query('PRAGMA table_info(provisioner_rollout_control)')->fetchAll(\PDO::FETCH_ASSOC),'name')
                : array_column($db->query('SHOW COLUMNS FROM provisioner_rollout_control')->fetchAll(\PDO::FETCH_ASSOC),'Field');
            if (!in_array('schema_revision',$controlColumns,true)) $db->exec('ALTER TABLE provisioner_rollout_control ADD COLUMN schema_revision INTEGER NOT NULL DEFAULT 0');
            $db->exec('UPDATE provisioner_rollout_control SET schema_revision=0 WHERE id=1');
            // CURSOR is reserved in MariaDB. Preserve progress from earlier
            // unreleased schemas without resetting or replaying a saved wave.
            $rolloutColumns = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? array_column($db->query('PRAGMA table_info(provisioner_rollouts)')->fetchAll(\PDO::FETCH_ASSOC),'name')
                : array_column($db->query('SHOW COLUMNS FROM provisioner_rollouts')->fetchAll(\PDO::FETCH_ASSOC),'Field');
            if (!in_array('wave_cursor',$rolloutColumns,true)) {
                if (!in_array('cursor',$rolloutColumns,true)) throw new \RuntimeException('Missing saved rollout wave position. Migration requires review.');
                $db->exec($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
                    ? 'ALTER TABLE provisioner_rollouts RENAME COLUMN `cursor` TO wave_cursor'
                    : 'ALTER TABLE provisioner_rollouts CHANGE COLUMN `cursor` wave_cursor INTEGER NOT NULL DEFAULT 0');
            } elseif (in_array('cursor',$rolloutColumns,true)) {
                // Module Admin may have added the new XML field before this
                // hook runs. Its default zero must not replay earlier waves.
                $db->exec('UPDATE provisioner_rollouts SET wave_cursor=`cursor`');
                $db->exec('ALTER TABLE provisioner_rollouts DROP COLUMN `cursor`');
            }
            if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $columns = array_column($db->query('PRAGMA table_info(provisioner_rollouts)')->fetchAll(\PDO::FETCH_ASSOC),'name');
                if (!in_array('summary',$columns,true)) $db->exec('ALTER TABLE provisioner_rollouts ADD COLUMN summary TEXT');
            } else {
                if (!$db->query("SHOW COLUMNS FROM provisioner_rollouts LIKE 'summary'")->fetch(\PDO::FETCH_ASSOC)) $db->exec('ALTER TABLE provisioner_rollouts ADD COLUMN summary TEXT');
                // Ownership and reports must really be transactional. Older PBXs
                // may have a non-InnoDB server default or legacy report tables.
                foreach (['provisioner_rollouts','provisioner_rollout_owners','provisioner_rollout_control','provisioner_batches','provisioner_batch_items','provisioner_devices','provisioner_push_jobs'] as $table) {
                    $status = $db->query("SHOW TABLE STATUS WHERE Name='$table'")->fetch(\PDO::FETCH_ASSOC);
                    if (!$status) throw new \RuntimeException('Missing provisioning table: ' . $table);
                    if (strtolower($status['Engine'] ?? '') !== 'innodb') $db->exec("ALTER TABLE $table ENGINE=InnoDB");
                }
            }
            foreach ([['provisioner_rollouts','rollout_queue','status,created_at'], ['provisioner_rollout_owners','rollout_owner_job','rollout_id'], ['provisioner_batch_items','batch_item_job','job_id,mac'], ['provisioner_batches','batch_parent','parent_id']] as $index) {
                [$table,$name,$columns] = $index;
                if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $db->exec("CREATE INDEX IF NOT EXISTS $name ON $table ($columns)");
                } else {
                    $found = $db->query("SHOW INDEX FROM $table WHERE Key_name='$name'")->fetch(\PDO::FETCH_ASSOC);
                    if (!$found) $db->exec("CREATE INDEX $name ON $table ($columns)");
                }
            }
            $db->exec('UPDATE provisioner_rollout_control SET schema_revision='.self::REVISION.' WHERE id=1');
        } finally { $db->setAttribute(\PDO::ATTR_ERRMODE, $old); }
    }

    public static function schedule($cron, $directory) {
        // FreePBX owns crontab installation and execution as the PBX service user.
        self::unschedule($cron);
        $cron->addLine('* * * * * /usr/bin/php ' . escapeshellarg($directory . '/rollout_worker.php') . ' >/dev/null 2>&1 # provisioner-rollout-worker');
    }

    public static function unschedule($cron) {
        // Cron::removeLine matches the complete line, not a marker substring.
        foreach ($cron->getAll() as $line) {
            if (preg_match('/#\s*provisioner-rollout-worker\s*$/D',$line)) $cron->removeLine($line);
        }
    }
}
