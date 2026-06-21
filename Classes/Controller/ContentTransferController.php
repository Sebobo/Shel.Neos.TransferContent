<?php

declare(strict_types=1);

namespace Shel\Neos\TransferContent\Controller;

use GuzzleHttp\Psr7\Response;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
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
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\NodeLabel\NodeLabelGeneratorInterface;
use Neos\Neos\Domain\Service\UserService as DomainUserService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Psr\Http\Message\ResponseInterface;

#[Flow\Scope('singleton')]
class ContentTransferController extends AbstractModuleController
{
    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected readonly Translator $translator;

    #[Flow\Inject]
    protected readonly DomainUserService $domainUserService;

    #[Flow\Inject]
    protected readonly ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected readonly SecurityContext $securityContext;

    #[Flow\Inject]
    protected readonly ContentRepositoryAuthorizationService $authorizationService;

    #[Flow\Inject]
    protected readonly WorkspaceService $workspaceService;

    #[Flow\Inject]
    protected readonly NodeLabelGeneratorInterface $nodeLabelGenerator;

    #[Flow\InjectConfiguration(path: 'contentRepositories', package: 'Neos.ContentRepositoryRegistry')]
    protected array $crSettings = [];

    public function indexAction(
        ?ContentRepositoryId $sourceContentRepository = null,
        ?ContentRepositoryId $targetContentRepository = null,
        ?WorkspaceName $sourceWorkspace = null,
        ?WorkspaceName $targetWorkspace = null,
        ?string $sourceDimensionValues = '{}',
        ?string $targetDimensionValues = '{}',
    ): void {
        $contentRepositoryIds = [];
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $crId) {
            $contentRepositoryIds[] = $crId->value;
        }

        $sourceWorkspace = $sourceWorkspace ?? WorkspaceName::forLive();
        $targetWorkspace = $targetWorkspace ?? WorkspaceName::forLive();
        $sourceContentRepository = $sourceContentRepository ?: ContentRepositoryId::fromString('default');
        $targetContentRepository = $targetContentRepository ?: ContentRepositoryId::fromString('default');

        $sourceWorkspaces = $this->getWorkspacesForCr($sourceContentRepository);
        $targetWorkspaces = $this->getWorkspacesForCr($targetContentRepository);

        $sourceDimensions = $this->buildDimensionConfig($sourceContentRepository);
        $targetDimensions = $this->buildDimensionConfig($targetContentRepository);

        $parsedSourceDimValues = json_decode($sourceDimensionValues, true, 512, JSON_THROW_ON_ERROR) ?: [];
        $parsedTargetDimValues = json_decode($targetDimensionValues, true, 512, JSON_THROW_ON_ERROR) ?: [];

        // Always merge defaults for missing dimensions
        if (!empty($sourceDimensions)) {
            foreach ($sourceDimensions as $dim) {
                if (!array_key_exists($dim['id'], $parsedSourceDimValues)) {
                    $firstValue = $dim['values'][0]['value'] ?? null;
                    if ($firstValue !== null) {
                        $parsedSourceDimValues[$dim['id']] = $firstValue;
                    }
                }
            }
        }
        if (!empty($targetDimensions)) {
            foreach ($targetDimensions as $dim) {
                if (!array_key_exists($dim['id'], $parsedTargetDimValues)) {
                    $firstValue = $dim['values'][0]['value'] ?? null;
                    if ($firstValue !== null) {
                        $parsedTargetDimValues[$dim['id']] = $firstValue;
                    }
                }
            }
        }

        $this->view->assignMultiple([
            'contentRepositoryIds' => $contentRepositoryIds,
            'sourceContentRepository' => $sourceContentRepository,
            'targetContentRepository' => $targetContentRepository,
            'sourceWorkspaces' => $sourceWorkspaces,
            'targetWorkspaces' => $targetWorkspaces,
            'sourceWorkspace' => $sourceWorkspace,
            'targetWorkspace' => $targetWorkspace,
            'sourceDimensions' => $sourceDimensions,
            'targetDimensions' => $targetDimensions,
            'sourceDimensionValues' => $parsedSourceDimValues,
            'targetDimensionValues' => $parsedTargetDimValues,
            'allowNodeMoving' => $this->settings['allowNodeMoving'],
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    /**
     * @throws AccessDenied
     * @throws \JsonException
     */
    public function treeChildrenAction(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName = null,
        ?NodeAggregateId $parentNodeId = null,
        string $dimensionValues = '{}',
    ): ResponseInterface {
        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
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

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['children' => $result], JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @throws AccessDenied
     * @throws StopActionException
     * @throws \JsonException
     * @throws Exception
     * @throws MissingActionNameException
     */
    public function copyNodeAction(
        string $sourceNodePath = '',
        string $targetParentNodePath = '',
        ?WorkspaceName $sourceWorkspace = null,
        ?WorkspaceName $targetWorkspace = null,
        bool $moveNodesInstead = false,
        ?ContentRepositoryId $sourceContentRepository = null,
        ?ContentRepositoryId $targetContentRepository = null,
        string $sourceDimensionValues = '{}',
        string $targetDimensionValues = '{}',
    ): void {
        $sourceContentRepository = $sourceContentRepository ?? ContentRepositoryId::fromString('default');
        $targetContentRepository = $targetContentRepository ?? ContentRepositoryId::fromString('default');

        $sourceCr = $this->contentRepositoryRegistry->get($sourceContentRepository);
        $targetCr = $this->contentRepositoryRegistry->get($targetContentRepository);

        $sourceWorkspace = $sourceWorkspace ?: WorkspaceName::forLive();
        $targetWorkspace = $targetWorkspace ?: WorkspaceName::forLive();

        $parsedSourceDim = json_decode($sourceDimensionValues, true, 512, JSON_THROW_ON_ERROR) ?: [];
        $parsedTargetDim = json_decode($targetDimensionValues, true, 512, JSON_THROW_ON_ERROR) ?: [];

        $sourceSubgraph = $sourceCr->getContentSubgraph(
            $sourceWorkspace,
            DimensionSpacePoint::fromArray($parsedSourceDim)
        );
        $targetSubgraph = $targetCr->getContentSubgraph(
            $targetWorkspace,
            DimensionSpacePoint::fromArray($parsedTargetDim)
        );

        $sourceNode = $sourceSubgraph->findNodeById(
            NodeAggregateId::fromString($sourceNodePath)
        );
        $targetParentNode = $targetSubgraph->findNodeById(
            NodeAggregateId::fromString($targetParentNodePath)
        );

        $sourceNodeTypeManager = $sourceCr->getNodeTypeManager();
        $targetNodeTypeManager = $targetCr->getNodeTypeManager();
        $sourceNodeType = $sourceNode?->nodeTypeName
            ? $sourceNodeTypeManager->getNodeType($sourceNode->nodeTypeName)
            : null;
        $targetNodeType = $targetParentNode?->nodeTypeName
            ? $targetNodeTypeManager->getNodeType($targetParentNode->nodeTypeName)
            : null;

        if ($sourceNode === null) {
            $this->addFlashMessage(
                $this->translate('error.sourceNodeNotFound'),
                'Error',
                Message::SEVERITY_ERROR
            );
        } elseif ($targetParentNode === null) {
            $this->addFlashMessage(
                $this->translate('error.targetParentNodeNotFound'),
                'Error',
                Message::SEVERITY_ERROR
            );
        } elseif ($sourceNodeType === null || !$sourceNodeType->isOfType(
                NodeTypeName::fromString('Neos.Neos:Document')
            )) {
            $this->addFlashMessage(
                $this->translate('error.invalidSourceNode', [
                    $sourceNode->nodeTypeName->value
                ]),
                'Error',
                Message::SEVERITY_ERROR
            );
        } elseif ($targetNodeType === null || !$targetNodeType->isOfType(
                NodeTypeName::fromString('Neos.Neos:Document')
            )) {
            $this->addFlashMessage(
                $this->translate('error.invalidTargetParentNode', [
                    $targetParentNode->nodeTypeName->value
                ]),
                'Error',
                Message::SEVERITY_ERROR
            );
        } elseif (!$targetNodeType->allowsChildNodeType($sourceNodeType)) {
            $this->addFlashMessage(
                $this->translate('error.sourceNodeNotAllowedAsChildNode'),
                'Error',
                Message::SEVERITY_ERROR
            );
        } elseif ($moveNodesInstead) {
            if (!$sourceContentRepository->equals($targetContentRepository)) {
                $this->addFlashMessage(
                    $this->translate('error.cannotMoveAcrossCr'),
                    'Error',
                    Message::SEVERITY_ERROR
                );
            } else {
                $this->moveNode($sourceCr, $sourceNode, $targetParentNode, $targetWorkspace);
            }
        } else {
            $this->copyNode(
                $sourceCr,
                $targetCr,
                $sourceNode,
                $targetParentNode,
            );
        }

        $this->redirect('index', null, null, [
            'sourceContentRepository' => $sourceContentRepository->value,
            'targetContentRepository' => $targetContentRepository->value,
            'sourceWorkspace' => $sourceWorkspace->value,
            'targetWorkspace' => $targetWorkspace->value,
            'sourceDimensionValues' => $sourceDimensionValues,
            'targetDimensionValues' => $targetDimensionValues,
        ]);
    }

    private function moveNode(
        ContentRepository $contentRepository,
        Node $sourceNode,
        Node $targetParentNode,
        WorkspaceName $targetWorkspaceName
    ): void {
        try {
            $contentRepository->handle(
                MoveNodeAggregate::create(
                    workspaceName: $targetWorkspaceName,
                    dimensionSpacePoint: $sourceNode->originDimensionSpacePoint->toDimensionSpacePoint(),
                    nodeAggregateId: $sourceNode->aggregateId,
                    relationDistributionStrategy: RelationDistributionStrategy::STRATEGY_GATHER_ALL,
                    newParentNodeAggregateId: $targetParentNode->aggregateId,
                )
            );
            $this->addFlashMessage(
                $this->translate('message.moved'),
                'Success'
            );
        } catch (\Exception $e) {
            $this->addFlashMessage(
                $this->translate('error.copyFailed', [$e->getMessage()]),
                'Error',
                Message::SEVERITY_ERROR
            );
        }
    }

    private function copyNode(
        ContentRepository $sourceCr,
        ContentRepository $targetCr,
        Node $sourceNode,
        Node $targetParentNode
    ): void {
        try {
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

            $this->addFlashMessage(
                $this->translate('message.copied'),
                'Success'
            );
        } catch (\Exception $e) {
            $this->addFlashMessage(
                $this->translate('error.copyFailed', [$e->getMessage()]),
                'Error',
                Message::SEVERITY_ERROR
            );
        }
    }

    /**
     * @throws AccessDenied
     */
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

    private function buildDimensionConfig(ContentRepositoryId $crId): array
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
                $values[] = [
                    'value' => $valId,
                    'label' => $valConfig['label'] ?? $valId,
                ];
            }

            $result[] = [
                'id' => $dimId,
                'label' => $dimConfig['label'] ?? $dimId,
                'values' => $values,
            ];
        }
        return $result;
    }

    private function getNodeTypeFilterForCr(ContentRepositoryId $crId): string
    {
        $filters = $this->settings['nodeTypeFilters'] ?? ['default' => 'Neos.Neos:Document'];
        return $filters[$crId->value] ?? $filters['default'] ?? 'Neos.Neos:Document';
    }

    private function getWorkspacesForCr(ContentRepositoryId $crId): array
    {
        $contentRepository = $this->contentRepositoryRegistry->get($crId);
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

    protected function translate(string $id, array $arguments = []): string
    {
        try {
            $translation = $this->translator->translateById(
                $id,
                $arguments,
                null,
                null,
                'ContentTransfer',
                'Shel.Neos.TransferContent'
            );
        } catch (\Exception) {
        }
        return $translation ?? $id;
    }
}
