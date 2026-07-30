<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messenger;

use App\Message\DeadLetterMessage;
use App\Message\ProcessJobMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Uid\Uuid;

final class DeadLetterTest extends KernelTestCase
{
    public function testDeadLetterMessageIsRoutedToDeadLetterQueue(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $messageBus = $container->get(
            MessageBusInterface::class,
        );

        $transport = $container->get(
            'messenger.transport.async_dead_letter',
        );

        self::assertInstanceOf(
            ReceiverInterface::class,
            $transport,
        );

        foreach ($transport->get() as $oldEnvelope) {
            $transport->ack($oldEnvelope);
        }

        $jobId = Uuid::v7()->toRfc4122();

        $messageBus->dispatch(
            new DeadLetterMessage(
                jobId: $jobId,
                originalMessageClass: ProcessJobMessage::class,
                errorMessage: 'Échec simulé pour le test AMQP.',
            ),
        );

        $envelope = $this->receiveOne($transport);
        $message = $envelope->getMessage();

        self::assertInstanceOf(
            DeadLetterMessage::class,
            $message,
        );

        self::assertSame($jobId, $message->jobId);

        self::assertSame(
            ProcessJobMessage::class,
            $message->originalMessageClass,
        );

        self::assertSame(
            'Échec simulé pour le test AMQP.',
            $message->errorMessage,
        );

        $transport->ack($envelope);
    }

    private function receiveOne(
        ReceiverInterface $receiver,
    ): Envelope {
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            foreach ($receiver->get() as $envelope) {
                return $envelope;
            }

            usleep(200_000);
        }

        self::fail(
            'Aucun message reçu depuis la Dead Letter Queue.',
        );
    }
}
