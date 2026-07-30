<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/var',
        __DIR__.'/vendor',

        __DIR__.'/src/Controller/Api/JobController.php',
        __DIR__.'/src/Entity/Job.php',
        __DIR__.'/src/Entity/JobLog.php',
        __DIR__.'/src/EventSubscriber/WorkerHeartbeatSubscriber.php',
    ])
    ->withPhpSets(
        php83: true,
    )
;