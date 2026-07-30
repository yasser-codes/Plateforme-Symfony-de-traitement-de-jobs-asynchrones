<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiRateLimitResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        foreach ($request->attributes->all() as $name => $value) {
            $prefix = '_rate_limit_header_';

            if (!str_starts_with($name, $prefix)) {
                continue;
            }

            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }

            $headerName = substr($name, strlen($prefix));

            $event->getResponse()->headers->set(
                $headerName,
                (string) $value,
            );
        }
    }
}
