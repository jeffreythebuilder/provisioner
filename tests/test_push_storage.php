<?php

// Pure migration contract double: no connection to the host MariaDB/FreePBX.
final class PushSchemaDatabaseDouble {
    public $type = 'text';
    public $mode = PDO::ERRMODE_SILENT;
    public $alters = 0;
    public $fail = false;
    public function getAttribute($attribute) { return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : $this->mode; }
    public function setAttribute($attribute, $value) { $this->mode = $value; }
    public function query($sql) {
        return new class($this->type) {
            private $type;
            public function __construct($type) { $this->type = $type; }
            public function fetch($mode) { return ['Type'=>$this->type]; }
        };
    }
    public function exec($sql) {
        if (strpos($sql, 'ALTER TABLE') === 0) {
            $this->alters++;
            if ($this->fail) throw new RuntimeException('Fixture migration denied');
            $this->type = 'mediumtext';
        }
        return 0;
    }
}

test('official push storage migration is idempotent and restores error mode', function () {
    $db = new PushSchemaDatabaseDouble();
    \FreePBX\modules\Provisioner\PushJobSchema::install($db);
    \FreePBX\modules\Provisioner\PushJobSchema::install($db);
    assertSame(1, $db->alters);
    assertSame('mediumtext', $db->type);
    assertSame(PDO::ERRMODE_SILENT, $db->mode);
    $db->type = 'longtext';
    \FreePBX\modules\Provisioner\PushJobSchema::install($db);
    assertSame(1, $db->alters, 'Never narrow larger existing storage');
    foreach (['install.php','upgrade.php'] as $hook) {
        assertTrue(strpos(file_get_contents(dirname(__DIR__) . '/' . $hook), 'PushJobSchema::install') !== false);
    }
});

test('missing migration blocks runtime without attempting schema repair', function () {
    $db = new PushSchemaDatabaseDouble();
    try {
        \FreePBX\modules\Provisioner\PushJobSchema::assertReady($db);
        throw new RuntimeException('Expected readiness failure');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'Module Admin') !== false);
    }
    assertSame(0, $db->alters);
    $db->fail = true;
    try {
        \FreePBX\modules\Provisioner\PushJobSchema::install($db);
        throw new RuntimeException('Expected migration failure');
    } catch (RuntimeException $error) {
        assertSame('Fixture migration denied', $error->getMessage());
    }
    assertSame(PDO::ERRMODE_SILENT, $db->mode);
});

test('300 phone reservation roundtrips a payload larger than MariaDB TEXT', function () {
    [$module, $db] = makeFixture();
    $phones = [];
    for ($i = 0; $i < 300; $i++) {
        $mac = sprintf('000fd3%06x', $i);
        insertDevice($db, $mac, (string)(1000 + $i), 'digium', $i % 2, 1);
        $phones[$mac] = '10.1.' . intdiv($i, 254) . '.' . ($i % 254 + 1);
    }
    $db->exec("UPDATE provisioner_devices SET description='" . str_repeat('x', 255) . "', static_mask='255.255.0.0',static_gw='10.1.0.254'");
    $id = str_repeat('a', 32);
    $snapshots = invokePrivate($module, 'capturePushJobSnapshots', array_keys($phones), $id);
    invokePrivate($module, 'reservePushVerificationJob', $id, $phones, $snapshots, []);
    $row = $db->query('SELECT * FROM provisioner_push_jobs')->fetch(PDO::FETCH_ASSOC);
    assertSame('prepared', $row['status']);
    assertTrue(strlen($row['payload']) > 65535);
    $payload = json_decode($row['payload'], true);
    assertSame($phones, $payload['phones']);
    assertSame($snapshots, $payload['snapshots']);
    assertSame(false, $module->processPushVerificationJob($id, [], [], microtime(true)-1), 'Prepared attempts cannot be executed by the verifier');
    assertTrue(invokePrivate($module, 'createPushVerificationJob', $id, array_slice($phones, 0, 1, true), $snapshots, [], false));
    assertSame(1, (int)$db->query('SELECT COUNT(*) FROM provisioner_push_jobs')->fetchColumn());
    assertSame('queued', $db->query('SELECT status FROM provisioner_push_jobs')->fetchColumn());
    assertSame(false, invokePrivate($module, 'createPushVerificationJob', $id, $phones, $snapshots, [], false));
});

test('invalid and oversized payloads fail before reservation', function () {
    [$module, $db] = makeFixture();
    foreach ([['bad'=>"\xff"], ['large'=>str_repeat('x', 16777216)]] as $payload) {
        try {
            invokePrivate($module, 'encodePushJobPayload', $payload);
            throw new RuntimeException('Expected encoding rejection');
        } catch (RuntimeException $error) {
            assertTrue(strpos($error->getMessage(), 'Could not encode') !== false || strpos($error->getMessage(), 'storage limit') !== false);
        }
    }
    assertSame(0, (int)$db->query('SELECT COUNT(*) FROM provisioner_push_jobs')->fetchColumn());
});

test('reservation failure rolls back job ownership before any notification', function () {
    foreach ([PDO::ERRMODE_EXCEPTION, PDO::ERRMODE_SILENT] as $mode) {
    [$module, $db] = makeFixture();
    $mac = '000fd300ff04';
    insertDevice($db, $mac, '1004', 'digium', 1, 1);
    $db->exec("UPDATE provisioner_devices SET last_push_job_id='previous-attempt'");
    $db->exec("CREATE TRIGGER fail_reservation BEFORE INSERT ON provisioner_push_jobs BEGIN SELECT RAISE(FAIL, 'fixture storage unavailable'); END");
    $db->setAttribute(PDO::ATTR_ERRMODE, $mode);
    try {
        invokePrivate($module, 'prepareBulkPushJob', str_repeat('d', 32), [$mac=>'10.0.0.4'], []);
        throw new RuntimeException('Expected preparation failure');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(), 'fixture storage unavailable') !== false || strpos($error->getMessage(), 'Could not persist') !== false);
    }
    assertTrue(!$db->inTransaction());
    assertSame('previous-attempt', $db->query('SELECT last_push_job_id FROM provisioner_devices')->fetchColumn());
    assertSame(0, (int)$db->query('SELECT COUNT(*) FROM provisioner_push_jobs')->fetchColumn());
    assertSame(1, (int)$db->query('SELECT needs_changes FROM provisioner_devices')->fetchColumn());
    }
});

test('failed activation retains durable planned payload and pending changes', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd300ff01';
    insertDevice($db, $mac, '1001', 'digium', 1, 1);
    $id = str_repeat('b', 32);
    $snapshots = invokePrivate($module, 'capturePushJobSnapshots', [$mac], $id);
    invokePrivate($module, 'reservePushVerificationJob', $id, [$mac=>'10.0.0.1'], $snapshots, []);
    $before = $db->query('SELECT payload FROM provisioner_push_jobs')->fetchColumn();
    $db->exec("CREATE TRIGGER fail_queue BEFORE UPDATE OF payload ON provisioner_push_jobs BEGIN SELECT RAISE(FAIL, 'fixture queue failure'); END");
    assertSame(false, invokePrivate($module, 'createPushVerificationJob', $id, [$mac=>'10.0.0.1'], $snapshots, [], false));
    assertSame($before, $db->query('SELECT payload FROM provisioner_push_jobs')->fetchColumn());
    assertSame('failed', $db->query('SELECT status FROM provisioner_push_jobs')->fetchColumn());
    assertSame(1, (int)$db->query('SELECT needs_changes FROM provisioner_devices')->fetchColumn());
    assertSame(1, (int)$db->query('SELECT custom_config FROM provisioner_devices')->fetchColumn());
});

test('abandoned reservations expire without replay or clearing changes', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd300ff02';
    insertDevice($db, $mac, '1002', 'digium', 0, 1);
    $id = str_repeat('c', 32);
    $snapshots = invokePrivate($module, 'capturePushJobSnapshots', [$mac], $id);
    invokePrivate($module, 'reservePushVerificationJob', $id, [$mac=>'10.0.0.2'], $snapshots, []);
    $db->exec("UPDATE provisioner_push_jobs SET created_at='2000-01-01 00:00:00'");
    invokePrivate($module, 'expirePushVerificationJobs');
    assertSame('failed', $db->query('SELECT status FROM provisioner_push_jobs')->fetchColumn());
    assertSame(1, (int)$db->query('SELECT needs_changes FROM provisioner_devices')->fetchColumn());
});

test('saved push failures preserve discovery next action', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd300ff03';
    insertDevice($db, $mac, '1003');
    $result = invokePrivate($module, 'runPushBatch', 'push', [$mac], function () use ($mac) {
        return ['success'=>false, 'failed'=>[['mac'=>$mac, 'error'=>'Identify handset first', 'next_action'=>'discover']]];
    });
    $report = $module->getBatchReport($result['batch_id']);
    assertSame('discover', $report['items'][0]['next_action']);
});
