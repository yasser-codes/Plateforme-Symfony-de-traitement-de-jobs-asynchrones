<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Message\DeadLetterMessage;
use App\Message\JobMessageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final readonly class FinalJobFailureListener
{
    public function __construct(
        private MessageBusInterface $messageBus,

        #[Autowire(service: 'monolog.logger.messenger')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        /*
         * L'événement est également déclenché pour les retries.
         * Nous devons agir uniquement après le dernier échec.
         */
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();

        /*
         * Nous ne traitons que les messages associés à nos jobs.
         */
        if (!$message instanceof JobMessageInterface) {
            return;
        }

        $jobId = $message->getJobId();
        $errorMessage = $event->getThrowable()->getMessage();

        $this->logger->critical(
            'Échec définitif détecté : création du message Dead Letter.',
            [
                'job_id' => $jobId,
                'message_class' => $message::class,
                'error_message' => $errorMessage,
            ],
        );

        $this->messageBus->dispatch(
            new DeadLetterMessage(
                jobId: $jobId,
                originalMessageClass: $message::class,
                errorMessage: $errorMessage,
            ),
        );
    }
}
