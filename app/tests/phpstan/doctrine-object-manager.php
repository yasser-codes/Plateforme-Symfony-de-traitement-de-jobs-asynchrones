<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$kernel = new Kernel(
    environment: 'test',
    debug: true,
);

$kernel->boot();

$entityManager = $kernel
    ->getContainer()
    ->get('doctrine')
    ->getManager();

if (!$entityManager instanceof EntityManagerInterface) {
    throw new RuntimeException('EntityManagerInterface introuvable.');
}

return $entityManager;
