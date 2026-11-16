<?php
namespace FreePBX\modules\Provisioner;

final class ReceiverAccess {
    public static function install($moduleDirectory) {
        // FreePBX Module Admin's upload copy can omit dotfiles. Keep the
        // canonical rule in a non-hidden file and restore it via module hooks.
        $source = $moduleDirectory . '/lib/discovery-receiver.htaccess.dist';
        $target = $moduleDirectory . '/.htaccess';
        $rules = file_get_contents($source);
        if ($rules === false || $rules === '') throw new \RuntimeException('Missing discovery receiver access rules.');
        if (is_link($target)) throw new \RuntimeException('Refusing to replace a linked module .htaccess.');
        if (is_file($target)) {
            if (file_get_contents($target) !== $rules) throw new \RuntimeException('Custom module .htaccess found; review it before installing Provisioner.');
            if (!chmod($target, 0644)) throw new \RuntimeException('Cannot set discovery receiver access permissions.');
            return;
        }
        $temporary = tempnam($moduleDirectory, 'receiver-access-');
        if ($temporary === false) throw new \RuntimeException('Cannot create discovery receiver access file.');
        try {
            if (file_put_contents($temporary, $rules, LOCK_EX) !== strlen($rules) || !chmod($temporary, 0644)) {
                throw new \RuntimeException('Cannot write discovery receiver access rules.');
            }
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                if (!chown($temporary, fileowner($moduleDirectory)) || !chgrp($temporary, filegroup($moduleDirectory))) {
                    throw new \RuntimeException('Cannot set discovery receiver access ownership.');
                }
            }
            if (!rename($temporary, $target)) throw new \RuntimeException('Cannot activate discovery receiver access rules.');
        } finally {
            if (file_exists($temporary)) unlink($temporary);
        }
    }
}
