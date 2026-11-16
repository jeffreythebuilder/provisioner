<?php
// Child process for staged hook execution: no production FreePBX bootstrap,
// database credentials, .htaccess target, or real Cron implementation.
$directory=getenv('PROVISIONER_MARIADB_TEST_DIRECTORY');
$database=$argv[1] ?? '';
if (!$directory || !preg_match('#^/tmp/opencode/provisioner-mariadb-[a-zA-Z0-9_]+$#D',$directory)
    || !preg_match('/^provisioner_test_[a-f0-9]{8}$/D',$database)) throw new RuntimeException('Unsafe hook fixture target.');
class FreePBX {
    public static $database;
    public static $cron;
    public static function Database() {return self::$database;}
    public static function Cron() {return self::$cron;}
}
$db=new PDO('mysql:unix_socket='.$directory.'/db.sock;dbname='.$database,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (realpath($db->query('SELECT @@datadir')->fetchColumn()) !== realpath($directory.'/data')) throw new RuntimeException('Not the private database.');
FreePBX::$database=$db;
FreePBX::$cron=new class {
    public $lines=[];
    public function getAll() {return $this->lines;}
    public function removeLine($line) {$index=array_search($line,$this->lines,true);if($index!==false){unset($this->lines[$index]);$this->lines=array_values($this->lines);}}
    public function addLine($line) {$this->lines[]=$line;}
};
ob_start();
$version=(string)simplexml_load_file(dirname(__DIR__).'/module.xml')->version;
foreach (['install.php','upgrade.php','upgrade.php'] as $index=>$hook) {
    $installed=(int)$db->query("SELECT COUNT(*) FROM modules WHERE modulename='provisioner'")->fetchColumn()>0;
    include $directory.'/hook-fixture/'.$hook;
    $count=(int)$db->query("SELECT COUNT(*) FROM modules WHERE modulename='provisioner'")->fetchColumn();
    if ($count!==($installed?1:0)) throw new RuntimeException('Hook must not pre-register or duplicate a module');
    // Exercise the core registration step that real Module Admin runs AFTER
    // the hook, rather than expecting the hook to register itself.
    if (!$installed) $db->prepare("INSERT INTO modules (modulename,version,enabled) VALUES ('provisioner',?,1)")->execute([$version]);
    else $db->prepare("UPDATE modules SET version=? WHERE modulename='provisioner'")->execute([$version]);
    if ($index===0) $db->prepare("INSERT INTO modules (modulename,version,enabled) VALUES ('provisioner',?,1)")->execute([$version]);
}
$messages=ob_get_clean();
echo json_encode(['scheduled_lines'=>count(FreePBX::$cron->lines),'hook_messages'=>$messages]);
