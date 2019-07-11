<?php

declare(strict_types=1);

namespace tests\Libero\JatsContentWorkflowBundle\EventListener;

use Exception;
use Libero\ApiProblemBundle\Event\CreateApiProblem;
use Libero\JatsContentWorkflowBundle\EventListener\UnknownContentTypeListener;
use Libero\JatsContentWorkflowBundle\Exception\UnknownContentType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use function GuzzleHttp\Psr7\uri_for;

final class UnknownContentTypeListenerTest extends TestCase
{
    /**
     * @test
     */
    public function it_adds_translated_properties() : void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource(
            'array',
            [
                'libero.jats_content_workflow.content_type.unknown.title' => 'es title',
                'libero.jats_content_workflow.content_type.unknown.details' => 'es details: %uri%',
            ],
            'es',
            'api_problem'
        );

        $listener = new UnknownContentTypeListener($translator);

        $request = new Request();
        $request->setLocale('es');

        $event = new CreateApiProblem($request, new UnknownContentType(uri_for('http://asset')));

        $listener->onCreateApiProblem($event);

        $this->assertXmlStringEqualsXmlString(
            '<problem xml:lang="es" xmlns="urn:ietf:rfc:7807">
                <status>400</status>
                <title>es title</title>
                <details>es details: http://asset</details>
            </problem>',
            $event->getDocument()->saveXML()
        );
    }

    /**
     * @test
     */
    public function it_ignores_other_exceptions() : void
    {
        $listener = new UnknownContentTypeListener(new IdentityTranslator());
        $event = new CreateApiProblem(new Request(), new Exception());

        $expected = $event->getDocument()->saveXML();

        $listener->onCreateApiProblem($event);

        $this->assertXmlStringEqualsXmlString(
            $expected,
            $event->getDocument()->saveXML()
        );
    }
}
