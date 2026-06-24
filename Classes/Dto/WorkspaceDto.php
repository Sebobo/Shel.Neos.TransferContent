<?php

declare(strict_types=1);

namespace Shel\Neos\TransferContent\Dto;

use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

final readonly class WorkspaceDto
{
    public function __construct(
        public WorkspaceName $workspaceName,
        public string $title,
    ) {
    }
}
