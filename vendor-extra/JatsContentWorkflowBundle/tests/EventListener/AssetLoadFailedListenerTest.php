<?php

declare(strict_types=1);

namespace tests\Libero\JatsContentWorkflowBundle\EventListener;

use Exception;
use Libero\ApiProblemBundle\Event\CreateApiProblem;
use Libero\JatsContentWorkflowBundle\EventListener\AssetLoadFailedListener;
use Libero\JatsContentWorkflowBundle\Exception\AssetLoadFailed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use function GuzzleHttp\Psr7\uri_for;

final class AssetLoadFailedListenerTest extends TestCase
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
                'libero.jats_content_workflow.asset.load_failed.title' => 'es title',
                'libero.jats_content_workflow.asset.load_failed.details' => 'es details: %asset% %reason%',
            ],
            'es',
            'api_problem'
        );

        $listener = new AssetLoadFailedListener($translator);

        $request = new Request();
        $request->setLocale('es');

        $event = new CreateApiProblem($request, new AssetLoadFailed(uri_for('http://asset'), 'foo'));

        $listener->onCreateApiProblem($event);

        $this->assertXmlStringEqualsXmlString(
            '<problem xml:lang="es" xmlns="urn:ietf:rfc:7807">
                <status>400</status>
                <title>es title</title>
                <details>es details: http://asset foo</details>
            </problem>',
            $event->getDocument()->saveXML()
        );
    }

    /**
     * @test
     */
    public function it_ignores_other_exceptions() : void
    {
        $listener = new AssetLoadFailedListener(new IdentityTranslator());
        $event = new CreateApiProblem(new Request(), new Exception());

        $expected = $event->getDocument()->saveXML();

        $listener->onCreateApiProblem($event);

        $this->assertXmlStringEqualsXmlString(
            $expected,
            $event->getDocument()->saveXML()
        );
    }
}
