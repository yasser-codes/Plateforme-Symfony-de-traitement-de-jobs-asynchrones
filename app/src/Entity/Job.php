<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\JobStatus;
use App\Repository\JobRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: JobRepository::class)]
#[ORM\Table(name: 'jobs')]
class Job
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['job:list', 'job:detail', 'job_log:list'])]
    private Uuid $id;

    #[ORM\Column(length: 50)]
    #[Groups(['job:list', 'job:detail'])]
    private string $type;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['job:detail'])]
    private array $payload;

    #[ORM\Column(enumType: JobStatus::class)]
    #[Groups(['job:list', 'job:detail'])]
    private JobStatus $status;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['job:list', 'job:detail'])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    #[Groups(['job:list', 'job:detail'])]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['job:detail'])]
    private ?string $errorMessage = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['job:list', 'job:detail'])]
    private int $retryCount = 0;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    #[Groups(['job:list', 'job:detail'])]
    private int $priority = 0;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['job:list', 'job:detail'])]
    private ?string $processedBy = null;

    /**
     * @var Collection<int, JobLog>
     */
    #[ORM\OneToMany(
        mappedBy: 'job',
        targetEntity: JobLog::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $logs;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        string $type,
        array $payload,
        int $priority = 0,
    ) {
        $this->id = Uuid::v7();
        $this->type = $type;
        $this->payload = $payload;
        $this->status = JobStatus::PENDING;
        $this->logs = new ArrayCollection();

        $this->setPriority($priority);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function getStatus(): JobStatus
    {
        return $this->status;
    }

    public function setStatus(JobStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(
        ?\DateTimeImmutable $completedAt,
    ): self {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function incrementRetryCount(): self
    {
        ++$this->retryCount;

        return $this;
    }

    public function resetRetryCount(): self
    {
        $this->retryCount = 0;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        if ($priority < -10 || $priority > 10) {
            throw new \InvalidArgumentException('La priorité doit être comprise entre -10 et 10.');
        }

        $this->priority = $priority;

        return $this;
    }

    public function getProcessedBy(): ?string
    {
        return $this->processedBy;
    }

    public function setProcessedBy(?string $processedBy): self
    {
        $this->processedBy = $processedBy;

        return $this;
    }

    /**
     * @return Collection<int, JobLog>
     */
    public function getLogs(): Collection
    {
        return $this->logs;
    }

    public function addLog(JobLog $log): self
    {
        if (!$this->logs->contains($log)) {
            $this->logs->add($log);
            $log->setJob($this);
        }

        return $this;
    }

    public function removeLog(JobLog $log): self
    {
        $this->logs->removeElement($log);

        return $this;
    }
}
