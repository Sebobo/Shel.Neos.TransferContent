<?php

declare(strict_types=1);

namespace Shel\Neos\TransferContent\Dto;

use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;

final readonly class TreeNodeDto
{
    public function __construct(
        public NodeAggregateId $nodeAggregateId,
        public string $label,
        public NodeTypeName $nodeTypeName,
        public bool $hasChildren,
    ) {
    }
}
