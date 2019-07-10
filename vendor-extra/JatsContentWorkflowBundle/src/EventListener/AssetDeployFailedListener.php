<?php

declare(strict_types=1);

namespace Libero\JatsContentWorkflowBundle\EventListener;

use Libero\ApiProblemBundle\Event\CreateApiProblem;
use Libero\ContentApiBundle\EventListener\TranslatingApiProblemListener;
use Libero\ContentApiBundle\EventListener\TranslationRequest;
use Libero\JatsContentWorkflowBundle\Exception\AssetDeployFailed;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

final class AssetDeployFailedListener
{
    use TranslatingApiProblemListener;

    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    protected function supports(Throwable $exception) : bool
    {
        return $exception instanceof AssetDeployFailed;
    }

    protected function status(CreateApiProblem $event) : int
    {
        return Response::HTTP_BAD_REQUEST;
    }

    protected function titleTranslation(CreateApiProblem $event) : TranslationRequest
    {
        return new TranslationRequest('libero.jats_content_workflow.asset.deploy_failed.title');
    }

    protected function detailsTranslation(CreateApiProblem $event) : ?TranslationRequest
    {
        /** @var AssetDeployFailed $exception */
        $exception = $event->getException();

        return new TranslationRequest(
            'libero.jats_content_workflow.asset.deploy_failed.details',
            ['%asset%' => $exception->getFrom(), '%target%' => $exception->getTo()]
        );
    }

    protected function getTranslator() : TranslatorInterface
    {
        return $this->translator;
    }
}
