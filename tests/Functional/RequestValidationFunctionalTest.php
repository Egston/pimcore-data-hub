<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Functional;

use Pimcore\Bundle\DataHubBundle\Controller\WebserviceController;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RequestVariableValidator;
use Pimcore\Bundle\DataHubBundle\Tests\Functional\Fixtures\KernelTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pins request-validation rejection against the real WebserviceController.
 *
 * The L3 overlay (`pimcore_data_hub_test.yaml`) enables request_validation for
 * the `default` test client with a fixture rules file that allows only
 * `getTestSwrOnlyItemListing`. Any other operation or any undeclared variable
 * sent to this enforced client triggers an HTTP 400 with a GraphQL-shaped
 * errors body carrying the reject prefix and reason slug.
 *
 * If the junk-request test returns 200 instead of 400, the test config's
 * `request_validation` block is not reaching the kernel.
 */
final class RequestValidationFunctionalTest extends KernelTestCase
{
    public function testJunkRequestRejectedWith400AndErrorBody(): void
    {
        $response = $this->sendGraphQL(
            'DisallowedOperation',
            'query DisallowedOperation { __typename }'
        );

        self::assertSame(400, $response->getStatusCode(), 'an enforced client must receive HTTP 400 for a disallowed operation');

        $body = json_decode((string)$response->getContent(), true);
        self::assertIsArray($body, 'response body must decode to an array');
        self::assertArrayHasKey('errors', $body, 'HTTP 400 from request-validation must carry a GraphQL errors key');
        self::assertIsArray($body['errors']);
        self::assertNotEmpty($body['errors'], 'errors array must not be empty');

        $first = $body['errors'][0];
        self::assertIsArray($first);
        self::assertArrayHasKey('message', $first);
        self::assertArrayHasKey('extensions', $first);
        self::assertIsArray($first['extensions']);
        self::assertArrayHasKey('category', $first['extensions']);
        self::assertSame('pimcore.datahub', $first['extensions']['category']);

        $message = (string)$first['message'];
        self::assertStringStartsWith(
            RequestVariableValidator::REJECT_MESSAGE_PREFIX,
            $message,
            'reject message must begin with the known prefix'
        );
        $reason = substr($message, strlen(RequestVariableValidator::REJECT_MESSAGE_PREFIX));
        self::assertSame(
            RequestVariableValidator::REASON_OPERATION_NOT_ALLOWED,
            $reason,
            'fixture allows only getTestSwrOnlyItemListing — a disallowed operation must yield operation-not-allowed'
        );
    }

    public function testValidRequestReturns200(): void
    {
        $response = $this->sendGraphQL(
            'getTestSwrOnlyItemListing',
            'query getTestSwrOnlyItemListing($defaultLanguage: String) { getTestSwrOnlyItemListing(defaultLanguage: $defaultLanguage) { edges { node { id } } } }',
            ['defaultLanguage' => 'en']
        );

        self::assertSame(200, $response->getStatusCode(), 'an allowed operation with declared variables must return HTTP 200');

        $body = json_decode((string)$response->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayNotHasKey(
            'errors',
            $body,
            'a 200 with errors means the resolver partially failed, not that validation passed'
        );
    }

    public function testBypassFlagIgnoredOnEnforcedPublicContent(): void
    {
        $response = $this->dispatchWithApikey(
            'DisallowedOperation',
            'query DisallowedOperation { __typename }',
            'test-bypass-key-do-not-use'
        );

        self::assertSame(400, $response->getStatusCode(), 'bypass apikey must be ignored for an enforced client — junk must still return 400');

        $body = json_decode((string)$response->getContent(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('errors', $body);
        self::assertIsArray($body['errors']);
        self::assertNotEmpty($body['errors']);
        $first = $body['errors'][0];
        self::assertIsArray($first);
        self::assertArrayHasKey('message', $first);
        self::assertStringStartsWith(RequestVariableValidator::REJECT_MESSAGE_PREFIX, (string)$first['message']);
    }

    /**
     * Variant of sendGraphQL that injects an `apikey` request header, exercising
     * the bypass-key path of WebserviceController with an enforced client.
     */
    private function dispatchWithApikey(
        string $operationName,
        string $query,
        string $apikey,
        string $clientname = 'default'
    ): JsonResponse {
        $body = json_encode([
            'operationName' => $operationName,
            'query' => $query,
            'variables' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            throw new \RuntimeException('datahub.functional: failed to encode GraphQL request body');
        }

        $request = Request::create(
            '/datahub/graphql/' . $clientname,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body
        );
        $request->headers->set('apikey', $apikey);
        $request->attributes->set('clientname', $clientname);

        $controller = \Pimcore::getContainer()->get(WebserviceController::class);
        if (!$controller instanceof WebserviceController) {
            throw new \RuntimeException('datahub.functional: WebserviceController service not available');
        }

        $response = $controller->webonyxAction(
            \Pimcore::getContainer()->get(\Pimcore\Bundle\DataHubBundle\GraphQL\Service::class),
            \Pimcore::getContainer()->get(\Pimcore\Localization\LocaleServiceInterface::class),
            \Pimcore::getContainer()->get(\Pimcore\Model\Factory::class),
            $request,
            \Pimcore::getContainer()->get(\Pimcore\Helper\LongRunningHelper::class),
            \Pimcore::getContainer()->get(\Pimcore\Bundle\DataHubBundle\Service\ResponseServiceInterface::class)
        );

        if (!$response instanceof JsonResponse) {
            throw new \RuntimeException('datahub.functional: webonyxAction did not return a JsonResponse');
        }

        $kernel = \Pimcore::getKernel();
        $eventDispatcher = \Pimcore::getContainer()->get('event_dispatcher');
        $terminateEvent = new TerminateEvent($kernel, $request, $response);
        $eventDispatcher->dispatch($terminateEvent, KernelEvents::TERMINATE);

        return $response;
    }
}
