<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Message\PriorityJobMessage;
use App\Message\ProcessJobMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Uid\Uuid;

final class AmqpRoutingTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;

    private ReceiverInterface $normalTransport;

    private ReceiverInterface $priorityTransport;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->messageBus = $container->get(
            MessageBusInterface::class,
        );

        $normalTransport = $container->get(
            'messenger.transport.async_normal',
        );

        $priorityTransport = $container->get(
            'messenger.transport.async_priority',
        );

        self::assertInstanceOf(
            ReceiverInterface::class,
            $normalTransport,
        );

        self::assertInstanceOf(
            ReceiverInterface::class,
            $priorityTransport,
        );

        $this->normalTransport = $normalTransport;
        $this->priorityTransport = $priorityTransport;

        $this->purge($this->normalTransport);
        $this->purge($this->priorityTransport);
    }

    public function testNormalMessageIsRoutedToNormalQueue(): void
    {
        $jobId = Uuid::v7()->toRfc4122();

        $this->messageBus->dispatch(
            new ProcessJobMessage($jobId),
        );

        $envelope = $this->receiveMessageForJob(
            $this->normalTransport,
            $jobId,
        );

        $message = $envelope->getMessage();

        self::assertInstanceOf(
            ProcessJobMessage::class,
            $message,
        );

        self::assertSame(
            $jobId,
            $message->getJobId(),
        );

        $this->normalTransport->ack($envelope);
    }

    public function testPriorityMessageIsRoutedToPriorityQueue(): void
    {
        $jobId = Uuid::v7()->toRfc4122();

        $this->messageBus->dispatch(
            new PriorityJobMessage($jobId),
        );

        $envelope = $this->receiveMessageForJob(
            $this->priorityTransport,
            $jobId,
        );

        $message = $envelope->getMessage();

        self::assertInstanceOf(
            PriorityJobMessage::class,
            $message,
        );

        self::assertSame(
            $jobId,
            $message->getJobId(),
        );

        $this->priorityTransport->ack($envelope);
    }

    private function receiveMessageForJob(
        ReceiverInterface $receiver,
        string $expectedJobId,
    ): Envelope {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            foreach ($receiver->get() as $envelope) {
                $message = $envelope->getMessage();

                if (
                    $message instanceof ProcessJobMessage
                    || $message instanceof PriorityJobMessage
                ) {
                    if ($message->getJobId() === $expectedJobId) {
                        return $envelope;
                    }
                }

                $receiver->ack($envelope);
            }

            usleep(200_000);
        }

        self::fail(
            'Aucun message AMQP correspondant au job attendu.',
        );
    }

    private function purge(
        ReceiverInterface $receiver,
    ): void {
        foreach ($receiver->get() as $envelope) {
            $receiver->ack($envelope);
        }
    }
}
