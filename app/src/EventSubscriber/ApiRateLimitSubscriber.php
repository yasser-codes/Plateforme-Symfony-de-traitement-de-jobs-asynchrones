<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class ApiRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimiterFactory $apiLimiter,
    ) {
    }

    /**
     * @return array<string, string|array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->isApiRequest($request)) {
            return;
        }

        $clientIp = $request->getClientIp() ?? 'unknown';

        $limit = $this->apiLimiter
            ->create($clientIp)
            ->consume(1);

        $retryAfter = $limit->getRetryAfter();

        $headers = [
            'X-RateLimit-Limit' => (string) $limit->getLimit(),
            'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
            'X-RateLimit-Reset' => (string) $retryAfter->getTimestamp(),
        ];

        if ($limit->isAccepted()) {
            foreach ($headers as $name => $value) {
                $request->attributes->set(
                    '_rate_limit_header_'.$name,
                    $value,
                );
            }

            return;
        }

        $seconds = max(
            1,
            $retryAfter->getTimestamp() - time(),
        );

        $headers['Retry-After'] = (string) $seconds;

        $event->setResponse(
            new JsonResponse(
                data: [
                    'error' => 'RATE_LIMIT_EXCEEDED',
                    'message' => 'Trop de requêtes. Réessayez dans quelques secondes.',
                    'limit' => $limit->getLimit(),
                    'remaining' => $limit->getRemainingTokens(),
                    'retry_at' => $retryAfter->format(DATE_ATOM),
                ],
                status: Response::HTTP_TOO_MANY_REQUESTS,
                headers: $headers,
            ),
        );
    }

    private function isApiRequest(Request $request): bool
    {
        return str_starts_with(
            $request->getPathInfo(),
            '/api/',
        );
    }
}
