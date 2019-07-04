<?php

declare(strict_types=1);

namespace Libero\ContentStore\EventListener;

use Libero\ApiProblemBundle\Event\CreateApiProblem;
use Libero\ContentApiBundle\EventListener\TranslatingApiProblemListener;
use Libero\ContentApiBundle\EventListener\TranslationRequest;
use Libero\ContentStore\Exception\UnknownContentType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

final class UnknownContentTypeListener
{
    use TranslatingApiProblemListener;

    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    protected function supports(Throwable $exception) : bool
    {
        return $exception instanceof UnknownContentType;
    }

    protected function status(CreateApiProblem $event) : int
    {
        return Response::HTTP_BAD_REQUEST;
    }

    protected function titleTranslation(CreateApiProblem $event) : TranslationRequest
    {
        return new TranslationRequest('libero.content_store.content_type.unknown.title');
    }

    protected function detailsTranslation(CreateApiProblem $event) : ?TranslationRequest
    {
        /** @var UnknownContentType $exception */
        $exception = $event->getException();

        return new TranslationRequest(
            'libero.content_store.content_type.unknown.details',
            ['uri' => $exception->getUri()]
        );
    }

    protected function getTranslator() : TranslatorInterface
    {
        return $this->translator;
    }
}
