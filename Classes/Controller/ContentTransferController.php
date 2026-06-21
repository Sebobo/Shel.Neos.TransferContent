<?php

declare(strict_types=1);

namespace Shel\Neos\TransferContent\Controller;

use GuzzleHttp\Psr7\Response;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Psr\Http\Message\ResponseInterface;
use Shel\Neos\TransferContent\Service\ContentTransferService;

#[Flow\Scope('singleton')]
class ContentTransferController extends AbstractModuleController
{
    protected $defaultViewObjectName = FusionView::class;

    #[Flow\Inject]
    protected readonly Translator $translator;

    #[Flow\Inject]
    protected readonly ContentTransferService $contentTransferService;

    /**
     * @throws \JsonException
     */
    public function indexAction(
        ?ContentRepositoryId $sourceContentRepository = null,
        ?ContentRepositoryId $targetContentRepository = null,
        ?WorkspaceName $sourceWorkspace = null,
        ?WorkspaceName $targetWorkspace = null,
        ?string $sourceDimensionValues = '{}',
        ?string $targetDimensionValues = '{}',
    ): void {
        $contentRepositoryIds = $this->contentTransferService->getContentRepositoryIds();

        $sourceWorkspace = $sourceWorkspace ?? WorkspaceName::forLive();
        $targetWorkspace = $targetWorkspace ?? WorkspaceName::forLive();
        $sourceContentRepository = $sourceContentRepository ?: ContentRepositoryId::fromString('default');
        $targetContentRepository = $targetContentRepository ?: ContentRepositoryId::fromString('default');

        $sourceWorkspaces = $this->contentTransferService->getWorkspacesForCr($sourceContentRepository);
        $targetWorkspaces = $this->contentTransferService->getWorkspacesForCr($targetContentRepository);

        $sourceDimensions = $this->contentTransferService->buildDimensionConfig($sourceContentRepository);
        $targetDimensions = $this->contentTransferService->buildDimensionConfig($targetContentRepository);

        $parsedSourceDimValues = json_decode($sourceDimensionValues, true, 512, JSON_THROW_ON_ERROR) ?: [];
        $parsedTargetDimValues = json_decode($targetDimensionValues, true, 512, JSON_THROW_ON_ERROR) ?: [];

        if (!empty($sourceDimensions)) {
            foreach ($sourceDimensions as $dim) {
                if (!array_key_exists($dim->id, $parsedSourceDimValues)) {
                    $firstValue = $dim->values[0]->value ?? null;
                    if ($firstValue !== null) {
                        $parsedSourceDimValues[$dim->id] = $firstValue;
                    }
                }
            }
        }
        if (!empty($targetDimensions)) {
            foreach ($targetDimensions as $dim) {
                if (!array_key_exists($dim->id, $parsedTargetDimValues)) {
                    $firstValue = $dim->values[0]->value ?? null;
                    if ($firstValue !== null) {
                        $parsedTargetDimValues[$dim->id] = $firstValue;
                    }
                }
            }
        }

        $flashMessages = $this->controllerContext->getFlashMessageContainer()->getMessagesAndFlush();
        $flashMessagesData = array_map(static function (Message $message): array {
            return [
                'title' => $message->getTitle(),
                'message' => $message->getMessage(),
                'severity' => strtolower($message->getSeverity()),
            ];
        }, $flashMessages);

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
            'flashMessagesData' => $flashMessagesData,
        ]);
    }

    /**
     * @throws \JsonException
     */
    public function treeChildrenAction(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName = null,
        ?NodeAggregateId $parentNodeId = null,
        string $dimensionValues = '{}',
    ): ResponseInterface {
        $children = $this->contentTransferService->getTreeChildrenData(
            $contentRepositoryId,
            $workspaceName,
            $parentNodeId,
            $dimensionValues,
        );

        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['children' => $children], JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @throws MissingActionNameException
     * @throws StopActionException
     * @throws \JsonException
     * @throws AccessDenied
     * @throws Exception
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

        $sourceCr = $this->contentTransferService->getContentRepository($sourceContentRepository);
        $targetCr = $this->contentTransferService->getContentRepository($targetContentRepository);

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

        $sourceNodeType = $sourceNode?->nodeTypeName
            ? $sourceCr->getNodeTypeManager()->getNodeType($sourceNode->nodeTypeName)
            : null;
        $targetNodeType = $targetParentNode?->nodeTypeName
            ? $targetCr->getNodeTypeManager()->getNodeType($targetParentNode->nodeTypeName)
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
                try {
                    $this->contentTransferService->moveNode(
                        $sourceCr,
                        $sourceNode,
                        $targetParentNode,
                        $targetWorkspace,
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
        } else {
            try {
                $result = $this->contentTransferService->copyNode(
                    $sourceCr,
                    $targetCr,
                    $sourceNode,
                    $targetParentNode,
                );
                $this->addFlashMessage(
                    $this->translate('message.copied', [
                        (string)$result->nodeCount,
                        (string)$result->variantCount,
                    ]),
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

        $this->redirect('index', null, null, [
            'sourceContentRepository' => $sourceContentRepository->value,
            'targetContentRepository' => $targetContentRepository->value,
            'sourceWorkspace' => $sourceWorkspace->value,
            'targetWorkspace' => $targetWorkspace->value,
            'sourceDimensionValues' => $sourceDimensionValues,
            'targetDimensionValues' => $targetDimensionValues,
        ]);
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
