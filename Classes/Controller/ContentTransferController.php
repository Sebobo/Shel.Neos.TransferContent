<?php

declare(strict_types=1);

namespace Shel\Neos\TransferContent\Controller;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\UserService as DomainUserService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;

/**
 * Controller for transferring content between sites in Neos CMS
 */
#[Flow\Scope('singleton')]
class ContentTransferController extends AbstractModuleController
{
    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected readonly SiteRepository $siteRepository;

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

    private function getContentRepository(): ContentRepository
    {
        return $this->contentRepositoryRegistry->get(
            ContentRepositoryId::fromString('default')
        );
    }

    /**
     * Shows form to transfer content
     */
    public function indexAction(
        ?Site $sourceSite = null,
        ?Site $targetSite = null,
        string $targetParentNodePath = '',
        ?Workspace $targetWorkspace = null
    ): void {
        $sites = $this->siteRepository->findOnline();
        $contentRepository = $this->getContentRepository();
        $contentRepositoryId = $contentRepository->id;

        $roles = $this->securityContext->getRoles();
        $userId = $this->domainUserService->getCurrentUser()?->getId();

        $workspaces = $contentRepository->findWorkspaces()
            ->filter(function (Workspace $workspace) use ($contentRepositoryId, $roles, $userId): bool {
                $permissions = $this->authorizationService->getWorkspacePermissions(
                    $contentRepositoryId,
                    $workspace->workspaceName,
                    $roles,
                    $userId
                );
                return $permissions->write;
            })
            ->getIterator();

        // Build workspace labels with metadata titles
        $workspaceOptions = [];
        foreach ($workspaces as $workspace) {
            $metadata = $this->workspaceService->getWorkspaceMetadata(
                $contentRepositoryId,
                $workspace->workspaceName
            );
            $workspaceOptions[] = [
                'workspace' => $workspace->workspaceName,
                'title' => $metadata->title->value,
            ];
        }

        $this->view->assignMultiple([
            'sites' => $sites,
            'workspaces' => $workspaceOptions,
            'sourceSite' => $sourceSite,
            'targetSite' => $targetSite,
            'targetParentNodePath' => $targetParentNodePath,
            'targetWorkspace' => $targetWorkspace,
            'allowNodeMoving' => $this->settings['allowNodeMoving'],
            'flashMessages' => $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush(),
        ]);
    }

    /**
     * @throws StopActionException
     * @Flow\Validate(argumentName="sourceNodePath", type="\Neos\Flow\Validation\Validator\NotEmptyValidator")
     * @Flow\Validate(argumentName="targetParentNodePath", type="\Neos\Flow\Validation\Validator\NotEmptyValidator")
     */
    public function copyNodeAction(
        Site $sourceSite,
        Site $targetSite,
        string $sourceNodePath,
        string $targetParentNodePath,
        ?Workspace $targetWorkspace = null,
        bool $moveNodesInstead = false
    ): void {
        $contentRepository = $this->getContentRepository();
        $targetWorkspaceName = $targetWorkspace
            ? $targetWorkspace->workspaceName
            : WorkspaceName::forLive();

        $sourceSubgraph = $this->getContentSubgraph($contentRepository, $targetWorkspaceName);
        $targetSubgraph = $this->getContentSubgraph($contentRepository, $targetWorkspaceName);

        $sourceNode = $sourceSubgraph->findNodeById(
            NodeAggregateId::fromString($sourceNodePath)
        );
        $targetParentNode = $targetSubgraph->findNodeById(
            NodeAggregateId::fromString($targetParentNodePath)
        );

        $nodeTypeManager = $contentRepository->getNodeTypeManager();
        $sourceNodeType = $sourceNode?->nodeTypeName
            ? $nodeTypeManager->getNodeType($sourceNode->nodeTypeName)
            : null;
        $targetNodeType = $targetParentNode?->nodeTypeName
            ? $nodeTypeManager->getNodeType($targetParentNode->nodeTypeName)
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
        } elseif ($sourceNodeType === null || !$targetNodeType->allowsChildNodeType($sourceNodeType)) {
            $this->addFlashMessage(
                $this->translate('error.sourceNodeNotAllowedAsChildNode'),
                'Error',
                Message::SEVERITY_ERROR
            );
        } elseif ($moveNodesInstead) {
            $this->moveNode($contentRepository, $sourceNode, $targetParentNode, $targetWorkspaceName);
        } else {
            $this->copyNode($contentRepository, $sourceNode, $targetParentNode, $targetWorkspaceName);
        }

        $this->redirect('index', null, null, [
            'sourceSite' => $sourceSite,
            'targetSite' => $targetSite,
            'targetParentNodePath' => $targetParentNodePath,
            'targetWorkspace' => $targetWorkspace,
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

    /**
     * Copy a node with all its children recursively to a new parent.
     *
     * In Neos 9's event-sourced content repository, copy requires
     * recreating node aggregates in the target location.
     */
    private function copyNode(
        ContentRepository $contentRepository,
        Node $sourceNode,
        Node $targetParentNode,
        WorkspaceName $targetWorkspaceName
    ): void {
        try {
            $sourceSubgraph = $this->getContentSubgraphFromNode($contentRepository, $sourceNode);

            $this->copyNodeRecursive(
                contentRepository: $contentRepository,
                sourceSubgraph: $sourceSubgraph,
                sourceNode: $sourceNode,
                targetParentNodeAggregateId: $targetParentNode->aggregateId,
                targetWorkspaceName: $targetWorkspaceName,
                sourceOriginDimensionSpacePoint: $sourceNode->originDimensionSpacePoint,
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

    private function copyNodeRecursive(
        ContentRepository $contentRepository,
        ContentSubgraphInterface $sourceSubgraph,
        Node $sourceNode,
        NodeAggregateId $targetParentNodeAggregateId,
        WorkspaceName $targetWorkspaceName,
        OriginDimensionSpacePoint $sourceOriginDimensionSpacePoint,
    ): void {
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

        $childNodes = $sourceSubgraph->findChildNodes(
            $sourceNode->aggregateId,
            FindChildNodesFilter::create()
        );

        foreach ($childNodes as $childNode) {
            $this->copyNodeRecursive(
                contentRepository: $contentRepository,
                sourceSubgraph: $sourceSubgraph,
                sourceNode: $childNode,
                targetParentNodeAggregateId: $newNodeAggregateId,
                targetWorkspaceName: $targetWorkspaceName,
                sourceOriginDimensionSpacePoint: $sourceOriginDimensionSpacePoint,
            );
        }
    }

    private function getContentSubgraph(
        ContentRepository $contentRepository,
        WorkspaceName $workspaceName
    ): ContentSubgraphInterface {
        return $contentRepository->getContentSubgraph(
            $workspaceName,
            DimensionSpacePoint::createWithoutDimensions()
        );
    }

    private function getContentSubgraphFromNode(
        ContentRepository $contentRepository,
        Node $node
    ): ContentSubgraphInterface {
        return $contentRepository->getContentSubgraph(
            $node->workspaceName,
            $node->dimensionSpacePoint
        );
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
            // Ignore exception
        }
        return $translation ?? $id;
    }
}
