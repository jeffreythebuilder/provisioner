<?php
namespace FreePBX\modules\Provisioner;

final class ActiveArp {
    public static function binary() {
        foreach (['/usr/sbin/arping','/usr/bin/arping','/sbin/arping','/bin/arping'] as $path) {
            if (is_file($path) && is_executable($path)) return $path;
        }
        return null;
    }

    public static function install() {
        // Optional enrichment only. Never grant the PHP/Python worker root or
        // raw-socket capabilities. The distribution owns arping's permissions.
        if (self::binary()) return true;
        // Module Admin is not an OS package manager. Check availability only:
        // never run apt/yum, edit sudoers, or assign capabilities from this hook.
        return false;
    }
}
