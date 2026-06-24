<?php

declare(strict_types=1);

namespace Shel\Neos\TransferContent\Dto;

final readonly class DimensionValueDto
{
    public function __construct(
        public string $value,
        public string $label,
    ) {
    }
}
