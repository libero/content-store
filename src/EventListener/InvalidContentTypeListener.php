<?php

declare(strict_types=1);

namespace Libero\ContentStore\EventListener;

use Libero\ApiProblemBundle\Event\CreateApiProblem;
use Libero\ContentApiBundle\EventListener\TranslatingApiProblemListener;
use Libero\ContentApiBundle\EventListener\TranslationRequest;
use Libero\ContentStore\Exception\InvalidContentType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

final class InvalidContentTypeListener
{
    use TranslatingApiProblemListener;

    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    protected function supports(Throwable $exception) : bool
    {
        return $exception instanceof InvalidContentType;
    }

    protected function status(CreateApiProblem $event) : int
    {
        return Response::HTTP_BAD_REQUEST;
    }

    protected function titleTranslation(CreateApiProblem $event) : TranslationRequest
    {
        return new TranslationRequest('libero.content_store.content_type.invalid.title');
    }

    protected function detailsTranslation(CreateApiProblem $event) : ?TranslationRequest
    {
        /** @var InvalidContentType $exception */
        $exception = $event->getException();

        return new TranslationRequest(
            'libero.content_store.content_type.invalid.details',
            ['content-type' => $exception->getContentType(), 'uri' => $exception->getUri()]
        );
    }

    protected function getTranslator() : TranslatorInterface
    {
        return $this->translator;
    }
}
