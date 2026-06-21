<?php

declare(strict_types=1);

namespace Shel\Neos\TransferContent\Dto;

final readonly class CopyResult
{
    public function __construct(
        public int $nodeCount,
        public int $variantCount,
    ) {
    }
}
