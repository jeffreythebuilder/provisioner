<?php

class AsteriskCliFixture extends LegacyEngineFixture {
    public $binaries = [];
    protected function asteriskBinaryCandidates() { return $this->binaries; }
}

test('registration identity and transport inspection work with the restricted Cron PATH',function () {
    [, $db, $directory] = makeFixture();
    $bin = $directory . '/sbin with spaces'; mkdir($bin);
    $executable = $bin . '/asterisk';
    $contact = '  Contact: 2000/sip:2000@192.168.188.80:5060 abcdef1234 Avail 1.000';
    $registrar = '/registrar/contact/2000;@' . str_pad('abcdef1234',32,'0') . ': '
        . json_encode(['endpoint'=>'2000','uri'=>'sip:2000@192.168.188.80:5060','user_agent'=>'Asterisk A20 2.10.0.6746 000fd3cf406b']);
    file_put_contents($executable,"#!/bin/sh\ncase \"\$2\" in\n"
        . "'pjsip show contacts') printf '%s\\n' " . escapeshellarg($contact) . ";;\n"
        . "'database show registrar/contact') printf '%s\\n' " . escapeshellarg($registrar) . ";;\n"
        . "'pjsip show transports') printf '%s\\n' '  Transport: mock-udp udp 0 0 0.0.0.0:5160';;\n"
        . "*) exit 1;;\nesac\n");
    chmod($executable,0700);
    $module = new AsteriskCliFixture(new TestFreePBX($db));
    $module->binaries = [$directory.'/missing-asterisk',$executable];
    $oldPath = getenv('PATH');
    putenv('PATH=/usr/bin:/bin');
    try {
        $contacts = invokePrivate($module,'getPjsipContacts',null,true);
        assertSame('Avail',$contacts['2000']['status']);
        assertSame('192.168.188.80',$contacts['2000']['ip']);
        assertSame('000fd3cf406b',$contacts['2000']['mac']);
        assertSame(5160,$module->getSipTransportInfo()['all']['UDP'][0]['port']);
        assertSame('/usr/bin:/bin',getenv('PATH'),'The module must not repair the host/worker PATH');
    } finally { $oldPath === false ? putenv('PATH') : putenv('PATH='.$oldPath); }
});

test('production Asterisk command resolution ignores PATH and quotes CLI arguments',function () {
    [, $db, $directory] = makeFixture();
    $module = new \FreePBX\modules\Provisioner(new TestFreePBX($db));
    $candidates = invokePrivate($module,'asteriskBinaryCandidates');
    assertSame(['/usr/sbin/asterisk','/usr/local/sbin/asterisk','/usr/bin/asterisk','/usr/local/bin/asterisk'],$candidates);
    $fixture = new AsteriskCliFixture(new TestFreePBX($db));
    $file = $directory.'/mock asterisk'; file_put_contents($file,"#!/bin/sh\nexit 0\n"); chmod($file,0700);
    $fixture->binaries = [$file];
    $command = 'core show version; touch /this-must-not-run';
    assertSame(escapeshellarg($file).' -rx '.escapeshellarg($command),invokePrivate($fixture,'asteriskCliCommand',$command));
    $fixture->binaries = [$directory.'/not-installed'];
    try {
        invokePrivate($fixture,'asteriskCliCommand','pjsip show contacts');
        throw new RuntimeException('Expected missing CLI failure');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(),'Asterisk CLI is unavailable') !== false);
    }
});
