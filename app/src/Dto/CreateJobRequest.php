<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateJobRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Le type du job est obligatoire.')]
        #[Assert\Choice(
            choices: [
                'report',
                'email_campaign',
                'image_processing',
            ],
            message: 'Le type doit être report, email_campaign ou image_processing.',
        )]
        public string $type,

        #[Assert\NotNull(message: 'Le payload est obligatoire.')]
        #[Assert\Type(
            type: 'array',
            message: 'Le payload doit être un objet JSON valide.',
        )]
        public array $payload,

        #[Assert\Range(
            min: -10,
            max: 10,
            notInRangeMessage: 'La priorité doit être comprise entre {{ min }} et {{ max }}.',
        )]
        public int $priority = 0,
    ) {
    }
}
