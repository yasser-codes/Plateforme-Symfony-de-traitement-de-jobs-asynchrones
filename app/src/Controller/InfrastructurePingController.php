<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class InfrastructurePingController
{
    #[Route(
        path: '/infra/ping',
        name: 'infrastructure_ping',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => 'symfony',
            'php_version' => PHP_VERSION,
            'environment' => $_ENV['APP_ENV'] ?? 'unknown',
        ]);
    }
}
