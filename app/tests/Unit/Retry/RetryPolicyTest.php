<?php

declare(strict_types=1);

namespace App\Tests\Unit\Retry;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class RetryPolicyTest extends TestCase
{
    public function testMessageWithoutPreviousRetryIsRetryable(): void
    {
        $strategy = $this->createStrategy();

        $envelope = new Envelope(
            new \stdClass(),
        );

        self::assertTrue(
            $strategy->isRetryable($envelope),
        );
    }

    public function testMessageIsRetryableBeforeMaximumRetries(): void
    {
        $strategy = $this->createStrategy();

        $envelope = $this->createEnvelopeWithRetryCount(2);

        self::assertTrue(
            $strategy->isRetryable($envelope),
        );
    }

    public function testMessageIsNotRetryableAtMaximumRetries(): void
    {
        $strategy = $this->createStrategy();

        $envelope = $this->createEnvelopeWithRetryCount(3);

        self::assertFalse(
            $strategy->isRetryable($envelope),
        );
    }

    public function testDelayUsesExponentialMultiplier(): void
    {
        $strategy = $this->createStrategy();

        self::assertSame(
            1000,
            $strategy->getWaitingTime(
                new Envelope(new \stdClass()),
            ),
        );

        self::assertSame(
            2000,
            $strategy->getWaitingTime(
                $this->createEnvelopeWithRetryCount(1),
            ),
        );

        self::assertSame(
            4000,
            $strategy->getWaitingTime(
                $this->createEnvelopeWithRetryCount(2),
            ),
        );

        self::assertSame(
            8000,
            $strategy->getWaitingTime(
                $this->createEnvelopeWithRetryCount(3),
            ),
        );
    }

    public function testDelayDoesNotExceedMaximumDelay(): void
    {
        $strategy = $this->createStrategy();

        $envelope = $this->createEnvelopeWithRetryCount(5);

        self::assertSame(
            10000,
            $strategy->getWaitingTime($envelope),
        );
    }

    public function testJitterAddsTenPercentVariation(): void
    {
        $strategy = new MultiplierRetryStrategy(
            maxRetries: 3,
            delayMilliseconds: 1000,
            multiplier: 2,
            maxDelayMilliseconds: 10000,
            jitter: 0.1,
        );

        $waitingTime = $strategy->getWaitingTime(
            new Envelope(new \stdClass()),
        );

        self::assertGreaterThanOrEqual(
            900,
            $waitingTime,
        );

        self::assertLessThanOrEqual(
            1100,
            $waitingTime,
        );
    }

    private function createStrategy(): MultiplierRetryStrategy
    {
        return new MultiplierRetryStrategy(
            maxRetries: 3,
            delayMilliseconds: 1000,
            multiplier: 2,
            maxDelayMilliseconds: 10000,
            jitter: 0,
        );
    }

    private function createEnvelopeWithRetryCount(
        int $retryCount,
    ): Envelope {
        return new Envelope(
            new \stdClass(),
            [
                new RedeliveryStamp($retryCount),
            ],
        );
    }
}
