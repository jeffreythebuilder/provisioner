<?php
require_once __DIR__ . '/lib/RolloutSchema.php';
\FreePBX\modules\Provisioner\RolloutSchema::unschedule(\FreePBX::Cron());
