<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\JobLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: JobLogRepository::class)]
#[ORM\Table(name: 'job_logs')]
#[ORM\Index(
    name: 'idx_job_logs_created_at',
    columns: ['created_at'],
)]
class JobLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['job_log:list'])]
    private Uuid $id;

    #[ORM\ManyToOne(
        targetEntity: Job::class,
        inversedBy: 'logs',
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Job $job;

    #[ORM\Column(length: 20)]
    #[Groups(['job_log:list'])]
    private string $level;

    #[ORM\Column(type: 'text')]
    #[Groups(['job_log:list'])]
    private string $message;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['job_log:list'])]
    private array $context;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['job_log:list'])]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $level,
        string $message,
        array $context = [],
    ) {
        $this->id = Uuid::v7();
        $this->level = strtoupper($level);
        $this->message = $message;
        $this->context = $context;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getJob(): Job
    {
        return $this->job;
    }

    public function setJob(Job $job): self
    {
        $this->job = $job;

        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): self
    {
        $this->level = strtoupper($level);

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setContext(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
