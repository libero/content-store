<?php

declare(strict_types=1);

namespace tests\Libero\JatsContentWorkflowBundle\EventListener;

use Exception;
use Libero\ApiProblemBundle\Event\CreateApiProblem;
use Libero\JatsContentWorkflowBundle\EventListener\AssetDeployFailedListener;
use Libero\JatsContentWorkflowBundle\Exception\AssetDeployFailed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use function GuzzleHttp\Psr7\uri_for;

final class AssetDeployFailedListenerTest extends TestCase
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
                'libero.jats_content_workflow.asset.deploy_failed.title' => 'es title',
                'libero.jats_content_workflow.asset.deploy_failed.details' => 'es details: %asset% %target%',
            ],
            'es',
            'api_problem'
        );

        $listener = new AssetDeployFailedListener($translator);

        $request = new Request();
        $request->setLocale('es');

        $event = new CreateApiProblem($request, new AssetDeployFailed(uri_for('http://from'), uri_for('http://to')));

        $listener->onCreateApiProblem($event);

        $this->assertXmlStringEqualsXmlString(
            '<problem xml:lang="es" xmlns="urn:ietf:rfc:7807">
                <status>400</status>
                <title>es title</title>
                <details>es details: http://from http://to</details>
            </problem>',
            $event->getDocument()->saveXML()
        );
    }

    /**
     * @test
     */
    public function it_ignores_other_exceptions() : void
    {
        $listener = new AssetDeployFailedListener(new IdentityTranslator());
        $event = new CreateApiProblem(new Request(), new Exception());

        $expected = $event->getDocument()->saveXML();

        $listener->onCreateApiProblem($event);

        $this->assertXmlStringEqualsXmlString(
            $expected,
            $event->getDocument()->saveXML()
        );
    }
}
