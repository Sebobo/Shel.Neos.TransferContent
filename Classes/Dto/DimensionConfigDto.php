<?php

declare(strict_types=1);

namespace Shel\Neos\TransferContent\Dto;

final readonly class DimensionConfigDto
{
    /**
     * @param list<DimensionValueDto> $values
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $values,
    ) {
    }
}
