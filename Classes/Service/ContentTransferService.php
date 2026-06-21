<?php

declare(strict_types=1);

namespace Shel\Neos\TransferContent\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\CountChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\Service\UserService as DomainUserService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;

#[Flow\Scope('singleton')]
class ContentTransferService
{
    #[Flow\InjectConfiguration(path: 'contentRepositories', package: 'Neos.ContentRepositoryRegistry')]
    protected array $crSettings;

    public function __construct(
        protected ContentRepositoryRegistry $contentRepositoryRegistry,
        protected SecurityContext $securityContext,
        protected DomainUserService $domainUserService,
        protected ContentRepositoryAuthorizationService $authorizationService,
        protected WorkspaceService $workspaceService,
        protected NodeLabelGeneratorInterface $nodeLabelGenerator,
    ) {
    }

    public function getContentRepository(ContentRepositoryId $crId): ContentRepository
    {
        return $this->contentRepositoryRegistry->get($crId);
    }

    public function getContentRepositoryIds(): array
    {
        $ids = [];
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $crId) {
            $ids[] = $crId->value;
        }
        return $ids;
    }

    public function getWorkspacesForCr(ContentRepositoryId $crId): array
    {
        $contentRepository = $this->getContentRepository($crId);
        $roles = $this->securityContext->getRoles();
        $userId = $this->domainUserService->getCurrentUser()?->getId();

        $workspaces = $contentRepository->findWorkspaces()
            ->filter(function (Workspace $workspace) use ($crId, $roles, $userId): bool {
                $permissions = $this->authorizationService->getWorkspacePermissions(
                    $crId,
                    $workspace->workspaceName,
                    $roles,
                    $userId
                );
                return $permissions->write;
            })
            ->getIterator();

        $result = [];
        foreach ($workspaces as $workspace) {
            $metadata = $this->workspaceService->getWorkspaceMetadata(
                $crId,
                $workspace->workspaceName
            );
            $result[] = [
                'workspace' => $workspace->workspaceName,
                'title' => $metadata->title->value,
            ];
        }
        return $result;
    }

    public function buildDimensionConfig(ContentRepositoryId $crId): array
    {
        $crConfig = $this->crSettings[$crId->value] ?? [];
        $contentDimensions = $crConfig['contentDimensions'] ?? [];

        $result = [];
        foreach ($contentDimensions as $dimId => $dimConfig) {
            if (!is_array($dimConfig)) {
                continue;
            }

            $values = [];
            foreach ($dimConfig['values'] ?? [] as $valId => $valConfig) {
                if (!is_array($valConfig)) {
                    continue;
                }
                array_push($values, ...$this->flattenDimensionValues($valId, $valConfig));
            }

            $result[] = [
                'id' => $dimId,
                'label' => $dimConfig['label'] ?? $dimId,
                'values' => $values,
            ];
        }
        return $result;
    }

    private function flattenDimensionValues(string $valueId, array $config, string $breadcrumb = ''): array
    {
        $label = $config['label'] ?? $valueId;
        $fullLabel = $breadcrumb !== '' ? $breadcrumb . ' → ' . $label : $label;

        $result = [
            [
                'value' => $valueId,
                'label' => $fullLabel,
            ],
        ];

        foreach ($config['specializations'] ?? [] as $specId => $specConfig) {
            if (!is_array($specConfig)) {
                continue;
            }
            array_push($result, ...$this->flattenDimensionValues($specId, $specConfig, $fullLabel));
        }

        return $result;
    }

    public function getNodeTypeFilterForCr(ContentRepositoryId $crId): string
    {
        $filters = $this->crSettings['nodeTypeFilters'] ?? ['default' => 'Neos.Neos:Document'];
        return $filters[$crId->value] ?? $filters['default'] ?? 'Neos.Neos:Document';
    }

    public function getTreeChildrenData(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        ?NodeAggregateId $parentNodeId,
        string $dimensionValues,
    ): array {
        $cr = $this->getContentRepository($contentRepositoryId);
        $parsedDimValues = json_decode($dimensionValues, true, 512, JSON_THROW_ON_ERROR) ?: [];
        $dsp = DimensionSpacePoint::fromArray($parsedDimValues);
        $subgraph = $cr->getContentSubgraph($workspaceName, $dsp);
        $nodeTypeFilter = $this->getNodeTypeFilterForCr($contentRepositoryId);

        if ($parentNodeId === null) {
            $rootNodeAggregate = $cr->getContentGraph($workspaceName)->findRootNodeAggregates(
                FindRootNodeAggregatesFilter::create()
            )->first();
            $children = $subgraph->findChildNodes(
                $rootNodeAggregate->nodeAggregateId,
                FindChildNodesFilter::create(nodeTypes: 'Neos.Neos:Node')
            );
        } else {
            $children = $subgraph->findChildNodes(
                $parentNodeId,
                FindChildNodesFilter::create(nodeTypes: $nodeTypeFilter)
            );
        }

        $result = [];
        foreach ($children as $child) {
            $label = $this->nodeLabelGenerator->getLabel($child);
            $hasChildren = $subgraph->countChildNodes(
                    $child->aggregateId,
                    CountChildNodesFilter::create(nodeTypes: $nodeTypeFilter)
                ) > 0;

            $result[] = [
                'nodeAggregateId' => $child->aggregateId->value,
                'label' => $label,
                'nodeType' => $child->nodeTypeName->value,
                'hasChildren' => $hasChildren,
            ];
        }

        return $result;
    }

    public function moveNode(
        ContentRepository $contentRepository,
        Node $sourceNode,
        Node $targetParentNode,
        WorkspaceName $targetWorkspaceName,
    ): void {
        $contentRepository->handle(
            MoveNodeAggregate::create(
                workspaceName: $targetWorkspaceName,
                dimensionSpacePoint: $sourceNode->originDimensionSpacePoint->toDimensionSpacePoint(),
                nodeAggregateId: $sourceNode->aggregateId,
                relationDistributionStrategy: RelationDistributionStrategy::STRATEGY_GATHER_ALL,
                newParentNodeAggregateId: $targetParentNode->aggregateId,
            )
        );
    }

    public function copyNode(
        ContentRepository $sourceCr,
        ContentRepository $targetCr,
        Node $sourceNode,
        Node $targetParentNode,
    ): void {
        $sourceSubgraph = $sourceCr->getContentSubgraph(
            $sourceNode->workspaceName,
            $sourceNode->dimensionSpacePoint
        );

        $this->copyNodeRecursive(
            contentRepository: $targetCr,
            sourceSubgraph: $sourceSubgraph,
            sourceNode: $sourceNode,
            targetParentNodeAggregateId: $targetParentNode->aggregateId,
            targetWorkspaceName: $targetParentNode->workspaceName,
            sourceOriginDimensionSpacePoint: $targetParentNode->originDimensionSpacePoint,
        );
    }

    private function copyNodeRecursive(
        ContentRepository $contentRepository,
        ContentSubgraphInterface $sourceSubgraph,
        Node $sourceNode,
        NodeAggregateId $targetParentNodeAggregateId,
        WorkspaceName $targetWorkspaceName,
        OriginDimensionSpacePoint $sourceOriginDimensionSpacePoint,
    ): void {
        if ($sourceNode->classification->isTethered()) {
            $targetSubgraph = $contentRepository->getContentSubgraph(
                $targetWorkspaceName,
                $sourceOriginDimensionSpacePoint->toDimensionSpacePoint()
            );
            $existingNode = $targetSubgraph->findNodeByPath(
                $sourceNode->name,
                $targetParentNodeAggregateId
            );

            if ($existingNode === null) {
                return;
            }

            $propertyValues = [];
            foreach ($sourceNode->properties as $propertyName => $propertyValue) {
                $propertyValues[$propertyName] = $propertyValue;
            }

            if ($propertyValues !== []) {
                $contentRepository->handle(
                    SetNodeProperties::create(
                        workspaceName: $targetWorkspaceName,
                        nodeAggregateId: $existingNode->aggregateId,
                        originDimensionSpacePoint: $sourceOriginDimensionSpacePoint,
                        propertyValues: PropertyValuesToWrite::fromArray($propertyValues),
                    )
                );
            }

            $parentNodeAggregateId = $existingNode->aggregateId;
        } else {
            $newNodeAggregateId = NodeAggregateId::create();

            $propertyValues = [];
            foreach ($sourceNode->properties as $propertyName => $propertyValue) {
                $propertyValues[$propertyName] = $propertyValue;
            }

            $contentRepository->handle(
                CreateNodeAggregateWithNode::create(
                    workspaceName: $targetWorkspaceName,
                    nodeAggregateId: $newNodeAggregateId,
                    nodeTypeName: $sourceNode->nodeTypeName,
                    originDimensionSpacePoint: $sourceOriginDimensionSpacePoint,
                    parentNodeAggregateId: $targetParentNodeAggregateId,
                    initialPropertyValues: PropertyValuesToWrite::fromArray($propertyValues),
                )
            );

            $parentNodeAggregateId = $newNodeAggregateId;
        }

        $childNodes = $sourceSubgraph->findChildNodes(
            $sourceNode->aggregateId,
            FindChildNodesFilter::create()
        );

        foreach ($childNodes as $childNode) {
            $this->copyNodeRecursive(
                contentRepository: $contentRepository,
                sourceSubgraph: $sourceSubgraph,
                sourceNode: $childNode,
                targetParentNodeAggregateId: $parentNodeAggregateId,
                targetWorkspaceName: $targetWorkspaceName,
                sourceOriginDimensionSpacePoint: $sourceOriginDimensionSpacePoint,
            );
        }
    }
}
