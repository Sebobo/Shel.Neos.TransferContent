<?php

declare(strict_types=1);

namespace Shel\Neos\TransferContent\Controller;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContextFactory;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\UserService as DomainUserService;
use Neos\Neos\Service\NodeOperations;

/**
 * Controller
 *
 * @Flow\Scope("singleton")
 */
class ContentTransferController extends AbstractModuleController
{

    /**
     * @var NodeOperations
     * @Flow\Inject
     */
    protected $nodeOperations;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var ContextFactory
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var Translator
     */
    protected $translator;

    /**
     * @Flow\Inject
     * @var DomainUserService
     */
    protected $domainUserService;

    /**
     * Shows form to transfer content
     */
    public function indexAction(?Site $sourceSite = null, ?Site $targetSite = null, string $targetParentNodePath = '', ?Workspace $targetWorkspace = null)
    {
        $sites = $this->siteRepository->findOnline();
        $workspaces = array_filter($this->workspaceRepository->findAll()->toArray(), function (Workspace $workspace) {
            return $this->domainUserService->currentUserCanPublishToWorkspace($workspace);
        });

        $this->view->assignMultiple([
            'sites' => $sites,
            'workspaces' => $workspaces,
            'sourceSite' => $sourceSite,
            'targetSite' => $targetSite,
            'targetParentNodePath' => $targetParentNodePath,
            'targetWorkspace' => $targetWorkspace,
            'allowNodeMoving' => $this->settings['allowNodeMoving'],
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
    )
    {
        /** @var ContentContext $sourceContext */
        $sourceContext = $this->contextFactory->create([
            'currentSite' => $sourceSite,
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true
        ]);

        /** @var ContentContext $targetContext */
        $targetContext = $this->contextFactory->create([
            'currentSite' => $targetSite,
            'workspaceName' => $targetWorkspace ? $targetWorkspace->getName() : 'live',
            'invisibleContentShown' => true,
            'inaccessibleContentShown' => true
        ]);

        $sourceNode = $sourceContext->getNodeByIdentifier($sourceNodePath);
        $targetParentNode = $targetContext->getNodeByIdentifier($targetParentNodePath);

        if ($sourceNode === null) {
            $this->addFlashMessage(
                $this->translate('error.sourceNodeNotFound'),
                'Error',
                Message::SEVERITY_ERROR
            );
        } else if ($targetParentNode === null) {
            $this->addFlashMessage(
                $this->translate('error.targetParentNodeNotFound'),
                'Error',
                Message::SEVERITY_ERROR
            );
        } else if (!$sourceNode->getNodeType()->isOfType('Neos.Neos:Document')) {
            $this->addFlashMessage(
                $this->translate('error.invalidSourceNode', [$sourceNode->getNodeType()]),
                'Error',
                Message::SEVERITY_ERROR
            );
        } else if (!$targetParentNode->getNodeType()->isOfType('Neos.Neos:Document')) {
            $this->addFlashMessage(
                $this->translate('error.invalidTargetParentNode', [$targetParentNode->getNodeType()]),
                'Error',
                Message::SEVERITY_ERROR
            );
        } else if (!$targetParentNode->isNodeTypeAllowedAsChildNode($sourceNode->getNodeType())) {
            $this->addFlashMessage(
                $this->translate('error.sourceNodeNotAllowedAsChildNode'),
                'Error',
                Message::SEVERITY_ERROR
            );
        } else {
            try {
                if ($moveNodesInstead) {
                    $this->nodeOperations->move($sourceNode, $targetParentNode, 'into');
                    $this->addFlashMessage(
                        $this->translate('message.moved'),
                        'Success'
                    );
                } else {
                    $this->nodeOperations->copy($sourceNode, $targetParentNode, 'into');
                    $this->addFlashMessage(
                        $this->translate('message.copied'),
                        'Success'
                    );
                }
            } catch (NodeException $e) {
                $this->addFlashMessage(
                    $this->translate('error.copyFailed', [$e->getReferenceCode()]),
                    'Error',
                    Message::SEVERITY_ERROR
                );
            }
        }

        $this->redirect('index', null, null, [
            'sourceSite' => $sourceSite,
            'targetSite' => $targetSite,
            'targetParentNodePath' => $targetParentNodePath,
            'targetWorkspace' => $targetWorkspace,
        ]);
    }

    protected function translate(string $id, array $arguments = []): string
    {
        try {
            $translation = $this->translator->translateById($id, $arguments, null, null, 'ContentTransfer', 'Shel.Neos.TransferContent');
        } catch (\Exception $e) {
            // Ignore exception
        }
        return $translation ?? $id;
    }
}
