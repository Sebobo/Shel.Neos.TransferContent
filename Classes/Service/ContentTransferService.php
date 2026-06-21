<?php

declare(strict_types=1);

namespace Shel\Neos\TransferContent\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePointSet;
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
use Shel\Neos\TransferContent\Dto\CopyResult;
use Shel\Neos\TransferContent\Dto\DimensionConfigDto;
use Shel\Neos\TransferContent\Dto\DimensionValueDto;
use Shel\Neos\TransferContent\Dto\TreeNodeDto;
use Shel\Neos\TransferContent\Dto\WorkspaceDto;

#[Flow\Scope('singleton')]
class ContentTransferService
{
    /**
     * @var array<string, mixed>
     */
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

    /**
     * @return list<string>
     */
    public function getContentRepositoryIds(): array
    {
        $ids = [];
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $crId) {
            $ids[] = $crId->value;
        }
        return $ids;
    }

    /**
     * @return list<WorkspaceDto>
     */
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
            $result[] = new WorkspaceDto(
                workspaceName: $workspace->workspaceName,
                title: $metadata->title->value,
            );
        }
        return $result;
    }

    /**
     * @return list<DimensionConfigDto>
     */
    public function buildDimensionConfig(ContentRepositoryId $crId): array
    {
        $crConfig = $this->crSettings[$crId->value] ?? [];
        if (!is_array($crConfig)) {
            $crConfig = [];
        }
        $contentDimensions = $crConfig['contentDimensions'] ?? [];
        if (!is_array($contentDimensions)) {
            $contentDimensions = [];
        }

        $result = [];
        foreach ($contentDimensions as $dimId => $dimConfig) {
            if (!is_array($dimConfig)) {
                continue;
            }
            if (!is_string($dimId)) {
                continue;
            }

            $values = [];
            $dimValues = $dimConfig['values'] ?? [];
            if (is_array($dimValues)) {
                foreach ($dimValues as $valId => $valConfig) {
                    if (!is_array($valConfig)) {
                        continue;
                    }
                    if (!is_string($valId)) {
                        continue;
                    }
                    $typedConfig = [];
                    foreach ($valConfig as $k => $v) {
                        if (is_string($k)) {
                            $typedConfig[$k] = $v;
                        }
                    }
                    array_push($values, ...$this->flattenDimensionValues($valId, $typedConfig));
                }
            }

            $dimLabel = $dimConfig['label'] ?? $dimId;
            if (!is_string($dimLabel)) {
                $dimLabel = $dimId;
            }

            $result[] = new DimensionConfigDto(
                id: $dimId,
                label: $dimLabel,
                values: $values,
            );
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<DimensionValueDto>
     */
    private function flattenDimensionValues(string $valueId, array $config, string $breadcrumb = ''): array
    {
        $label = $config['label'] ?? $valueId;
        if (!is_string($label)) {
            $label = $valueId;
        }
        $fullLabel = $breadcrumb !== '' ? $breadcrumb . ' → ' . $label : $label;

        $result = [
            new DimensionValueDto(
                value: $valueId,
                label: $fullLabel,
            ),
        ];

        $specializations = $config['specializations'] ?? [];
        if (is_array($specializations)) {
            foreach ($specializations as $specId => $specConfig) {
                if (!is_array($specConfig)) {
                    continue;
                }
                if (!is_string($specId)) {
                    continue;
                }
                $typedSpecConfig = [];
                foreach ($specConfig as $k => $v) {
                    if (is_string($k)) {
                        $typedSpecConfig[$k] = $v;
                    }
                }
                array_push($result, ...$this->flattenDimensionValues($specId, $typedSpecConfig, $fullLabel));
            }
        }

        return $result;
    }

    /**
     * @return array<string, list<string>>
     */
    private function getDimensionValuesMap(ContentRepositoryId $crId): array
    {
        $crConfig = $this->crSettings[$crId->value] ?? [];
        if (!is_array($crConfig)) {
            $crConfig = [];
        }
        $contentDimensions = $crConfig['contentDimensions'] ?? [];
        if (!is_array($contentDimensions)) {
            $contentDimensions = [];
        }

        $map = [];
        foreach ($contentDimensions as $dimId => $dimConfig) {
            if (!is_array($dimConfig)) {
                continue;
            }
            if (!is_string($dimId)) {
                continue;
            }
            $values = [];
            $dimValues = $dimConfig['values'] ?? [];
            if (is_array($dimValues)) {
                $typedDimValues = [];
                foreach ($dimValues as $k => $v) {
                    if (is_string($k)) {
                        $typedDimValues[$k] = $v;
                    }
                }
                $this->collectDimensionValues($typedDimValues, $values);
            }
            $map[$dimId] = $values;
        }
        return $map;
    }

    /**
     * @param array<mixed> $valuesConfig
     * @param list<string> &$values
     */
    private function collectDimensionValues(array $valuesConfig, array &$values): void
    {
        foreach ($valuesConfig as $valId => $valConfig) {
            if (!is_string($valId)) {
                continue;
            }
            if (!is_array($valConfig)) {
                continue;
            }
            $values[] = $valId;
            $specializations = $valConfig['specializations'] ?? [];
            if (is_array($specializations)) {
                $typedSpecs = [];
                foreach ($specializations as $k => $v) {
                    if (is_string($k)) {
                        $typedSpecs[$k] = $v;
                    }
                }
                $this->collectDimensionValues($typedSpecs, $values);
            }
        }
    }

    /**
     * @return list<OriginDimensionSpacePoint>
     */
    private function filterCompatibleOriginDimensionSpacePoints(
        OriginDimensionSpacePointSet $odspSet,
        ContentRepository $targetCr,
    ): array {
        $targetDimValues = $this->getDimensionValuesMap($targetCr->id);

        $compatible = [];
        foreach ($odspSet as $odsp) {
            $allMatch = true;
            foreach ($odsp->coordinates as $dimName => $dimValue) {
                $targetValues = $targetDimValues[$dimName] ?? [];
                if (!in_array($dimValue, $targetValues, true)) {
                    $allMatch = false;
                    break;
                }
            }
            if ($allMatch) {
                $compatible[] = $odsp;
            }
        }
        return $compatible;
    }

    public function getNodeTypeFilterForCr(ContentRepositoryId $crId): string
    {
        $filters = $this->crSettings['nodeTypeFilters'] ?? ['default' => 'Neos.Neos:Document'];
        if (!is_array($filters)) {
            return 'Neos.Neos:Document';
        }
        $filter = $filters[$crId->value] ?? $filters['default'] ?? 'Neos.Neos:Document';
        if (!is_string($filter)) {
            return 'Neos.Neos:Document';
        }
        return $filter;
    }

    /**
     * @return list<TreeNodeDto>
     */
    public function getTreeChildrenData(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        ?NodeAggregateId $parentNodeId,
        string $dimensionValues,
    ): array {
        $cr = $this->getContentRepository($contentRepositoryId);
        $decodedDimValues = json_decode($dimensionValues, true, 512, JSON_THROW_ON_ERROR);
        $parsedDimValues = [];
        if (is_array($decodedDimValues)) {
            foreach ($decodedDimValues as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $parsedDimValues[$key] = $value;
                }
            }
        }
        $dsp = DimensionSpacePoint::fromArray($parsedDimValues);
        $subgraph = $cr->getContentSubgraph($workspaceName, $dsp);
        $nodeTypeFilter = $this->getNodeTypeFilterForCr($contentRepositoryId);

        if ($parentNodeId === null) {
            $rootNodeAggregate = $cr->getContentGraph($workspaceName)->findRootNodeAggregates(
                FindRootNodeAggregatesFilter::create()
            )->first();
            if ($rootNodeAggregate === null) {
                return [];
            }
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

            $result[] = new TreeNodeDto(
                nodeAggregateId: $child->aggregateId,
                label: $label,
                nodeTypeName: $child->nodeTypeName,
                hasChildren: $hasChildren,
            );
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
    ): CopyResult {
        $sourceContentGraph = $sourceCr->getContentGraph($sourceNode->workspaceName);
        $sourceAggregate = $sourceContentGraph->findNodeAggregateById($sourceNode->aggregateId);

        if ($sourceAggregate === null) {
            return new CopyResult(nodeCount: 0, variantCount: 0);
        }

        $compatibleODSPs = $this->filterCompatibleOriginDimensionSpacePoints(
            $sourceAggregate->occupiedDimensionSpacePoints,
            $targetCr,
        );

        $nodeCount = 0;
        foreach ($compatibleODSPs as $odsp) {
            $dsp = $odsp->toDimensionSpacePoint();
            $sourceVariantSubgraph = $sourceCr->getContentSubgraph($sourceNode->workspaceName, $dsp);
            $sourceVariant = $sourceVariantSubgraph->findNodeById($sourceNode->aggregateId);

            if ($sourceVariant === null) {
                continue;
            }

            $targetVariantSubgraph = $targetCr->getContentSubgraph($targetParentNode->workspaceName, $dsp);
            $targetParentVariant = $targetVariantSubgraph->findNodeById($targetParentNode->aggregateId);

            if ($targetParentVariant === null) {
                continue;
            }

            $nodeCount += $this->copyNodeRecursive(
                contentRepository: $targetCr,
                sourceSubgraph: $sourceVariantSubgraph,
                sourceNode: $sourceVariant,
                targetParentNodeAggregateId: $targetParentVariant->aggregateId,
                targetWorkspaceName: $targetParentNode->workspaceName,
                sourceOriginDimensionSpacePoint: $targetParentVariant->originDimensionSpacePoint,
            );
        }

        return new CopyResult(
            nodeCount: $nodeCount,
            variantCount: count($compatibleODSPs),
        );
    }

    private function copyNodeRecursive(
        ContentRepository $contentRepository,
        ContentSubgraphInterface $sourceSubgraph,
        Node $sourceNode,
        NodeAggregateId $targetParentNodeAggregateId,
        WorkspaceName $targetWorkspaceName,
        OriginDimensionSpacePoint $sourceOriginDimensionSpacePoint,
    ): int {
        $count = 0;

        if ($sourceNode->classification->isTethered()) {
            if ($sourceNode->name === null) {
                return 0;
            }
            $targetSubgraph = $contentRepository->getContentSubgraph(
                $targetWorkspaceName,
                $sourceOriginDimensionSpacePoint->toDimensionSpacePoint()
            );
            $existingNode = $targetSubgraph->findNodeByPath(
                $sourceNode->name,
                $targetParentNodeAggregateId
            );

            if ($existingNode === null) {
                return 0;
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

        $count++;

        $childNodes = $sourceSubgraph->findChildNodes(
            $sourceNode->aggregateId,
            FindChildNodesFilter::create()
        );

        foreach ($childNodes as $childNode) {
            $count += $this->copyNodeRecursive(
                contentRepository: $contentRepository,
                sourceSubgraph: $sourceSubgraph,
                sourceNode: $childNode,
                targetParentNodeAggregateId: $parentNodeAggregateId,
                targetWorkspaceName: $targetWorkspaceName,
                sourceOriginDimensionSpacePoint: $sourceOriginDimensionSpacePoint,
            );
        }

        return $count;
    }
}
