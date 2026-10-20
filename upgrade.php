<?php
global $db;
$version = '1.4.41';
$rawname = 'provisioner';

// Same approach as install.php — write to BOTH connections
$sql = "DELETE FROM modules WHERE modulename='$rawname'";
$db->query($sql);
\FreePBX::Database()->exec($sql);
$sql = "INSERT INTO modules (modulename, version, enabled) SELECT '$rawname', '$version', 1 WHERE NOT EXISTS (SELECT 1 FROM modules WHERE modulename='$rawname')";
$db->query($sql);
\FreePBX::Database()->exec($sql);
\FreePBX::Database()->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
