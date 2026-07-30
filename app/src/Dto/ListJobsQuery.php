<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\JobStatus;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ListJobsQuery
{
    public function __construct(
        #[Assert\Positive(message: 'La page doit être supérieure ou égale à 1.')]
        public int $page = 1,

        #[Assert\Range(
            min: 1,
            max: 100,
            notInRangeMessage: 'La limite doit être comprise entre {{ min }} et {{ max }}.',
        )]
        public int $limit = 10,

        #[Assert\Choice(
            callback: [JobStatus::class, 'values'],
            message: 'Le statut fourni est invalide.',
        )]
        public ?string $status = null,

        #[Assert\Choice(
            choices: [
                'report',
                'email_campaign',
                'image_processing',
            ],
            message: 'Le type fourni est invalide.',
        )]
        public ?string $type = null,

        #[Assert\Range(
            min: -10,
            max: 10,
            notInRangeMessage: 'La priorité doit être comprise entre {{ min }} et {{ max }}.',
        )]
        public ?int $priority = null,
    ) {
    }
}
