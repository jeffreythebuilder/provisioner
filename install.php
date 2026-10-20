<?php
global $db;
$version = '1.4.41';
$rawname = 'provisioner';

// Write to BOTH connections:
// global $db (PEAR DB) — modulefunctions.class.php reads/writes version here
// \FreePBX::Database() (PDO) — install.php uses this directly
//
// Both handles may use the same database. Guard the insert so the second
// write cannot duplicate the module row (modulename has no unique key).
$sql = "DELETE FROM modules WHERE modulename='$rawname'";
$db->query($sql);
\FreePBX::Database()->exec($sql);
$sql = "INSERT INTO modules (modulename, version, enabled) SELECT '$rawname', '$version', 1 WHERE NOT EXISTS (SELECT 1 FROM modules WHERE modulename='$rawname')";
$db->query($sql);
\FreePBX::Database()->exec($sql);
\FreePBX::Database()->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
