<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkerCommandTest extends KernelTestCase
{
    public function testMessengerStatsCommandIsAvailable(): void
    {
        self::bootKernel();

        $application = new Application(self::$kernel);

        $command = $application->find('messenger:stats');

        self::assertSame(
            'messenger:stats',
            $command->getName(),
        );
    }

    public function testMessengerStatsCommandExecutesSuccessfully(): void
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $command = $application->find('messenger:stats');

        $commandTester = new CommandTester($command);

        $exitCode = $commandTester->execute([
            '--format' => 'json',
        ]);

        self::assertSame(
            Command::SUCCESS,
            $exitCode,
        );
    }

    public function testMessengerStatsReturnsConfiguredTransports(): void
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $command = $application->find('messenger:stats');

        $commandTester = new CommandTester($command);

        $commandTester->execute([
            '--format' => 'json',
        ]);

        $output = trim($commandTester->getDisplay());

        $decodedOutput = json_decode(
            $output,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($decodedOutput);

        self::assertArrayHasKey(
            'transports',
            $decodedOutput,
        );

        $transports = $decodedOutput['transports'];

        self::assertIsArray($transports);

        self::assertArrayHasKey(
            'async_normal',
            $transports,
        );

        self::assertArrayHasKey(
            'async_priority',
            $transports,
        );

        self::assertArrayHasKey(
            'failed_storage',
            $transports,
        );

        self::assertArrayHasKey(
            'async_dead_letter',
            $transports,
        );
    }

    public function testEveryTransportInitiallyHasValidCount(): void
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $command = $application->find('messenger:stats');

        $commandTester = new CommandTester($command);

        $commandTester->execute([
            '--format' => 'json',
        ]);

        $output = trim($commandTester->getDisplay());

        /** @var array<string, mixed> $stats */
        $stats = json_decode(
            $output,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey(
            'transports',
            $stats,
        );

        $transports = $stats['transports'];

        self::assertIsArray($transports);

        foreach ($transports as $transport => $transportStats) {
            self::assertIsString($transport);
            self::assertIsArray($transportStats);

            self::assertArrayHasKey(
                'count',
                $transportStats,
            );

            self::assertIsInt(
                $transportStats['count'],
            );

            self::assertGreaterThanOrEqual(
                0,
                $transportStats['count'],
            );
        }
    }
}
