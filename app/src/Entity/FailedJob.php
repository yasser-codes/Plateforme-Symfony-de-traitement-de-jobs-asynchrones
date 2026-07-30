<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FailedJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FailedJobRepository::class)]
class FailedJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $jobId;

    #[ORM\Column(length: 255)]
    private string $messageClass;

    #[ORM\Column(type: Types::TEXT)]
    private string $errorMessage;

    #[ORM\Column]
    private int $retryCount = 0;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $failedAt;

    #[ORM\Column(
        type: Types::DATE_IMMUTABLE,
        nullable: true,
    )]
    private ?\DateTimeImmutable $handleAt = null;

    public function __construct()
    {
        $this->failedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJobId(): Uuid
    {
        return $this->jobId;
    }

    public function setJobId(Uuid $jobId): static
    {
        $this->jobId = $jobId;

        return $this;
    }

    public function getMessageClass(): string
    {
        return $this->messageClass;
    }

    public function setMessageClass(
        string $messageClass,
    ): static {
        $this->messageClass = $messageClass;

        return $this;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(
        string $errorMessage,
    ): static {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function setRetryCount(
        int $retryCount,
    ): static {
        $this->retryCount = $retryCount;

        return $this;
    }

    public function getFailedAt(): \DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function setFailedAt(
        \DateTimeImmutable $failedAt,
    ): static {
        $this->failedAt = $failedAt;

        return $this;
    }

    public function getHandleAt(): ?\DateTimeImmutable
    {
        return $this->handleAt;
    }

    public function setHandleAt(
        ?\DateTimeImmutable $handleAt,
    ): static {
        $this->handleAt = $handleAt;

        return $this;
    }
}
