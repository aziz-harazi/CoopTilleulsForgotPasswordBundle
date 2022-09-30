<?php

/*
 * This file is part of the CoopTilleulsForgotPasswordBundle package.
 *
 * (c) Vincent CHALAMON <vincent@les-tilleuls.coop>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

use App\Entity\User;
use Behat\Behat\Context\Context;
use CoopTilleuls\ForgotPasswordBundle\Manager\PasswordTokenManager;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mailer\DataCollector\MessageDataCollector;
use Symfony\Component\Mime\RawMessage;

/**
 * @author Vincent CHALAMON <vincent@les-tilleuls.coop>
 */
final class FeatureContext implements Context
{
    /**
     * @var Registry
     */
    private $doctrine;

    /**
     * @var Client|KernelBrowser
     */
    private $client;

    /**
     * @var PasswordTokenManager
     */
    private $passwordTokenManager;

    /**
     * @var Application
     */
    private $application;

    /**
     * @var OutputInterface
     */
    private $output;

    public function __construct($client, Registry $doctrine, PasswordTokenManager $passwordTokenManager, KernelInterface $kernel)
    {
        $this->client = $client;
        $this->doctrine = $doctrine;
        $this->passwordTokenManager = $passwordTokenManager;
        $this->application = new Application($kernel);
        $this->output = new BufferedOutput();
    }

    /**
     * @BeforeScenario
     */
    public function resetDatabase(): void
    {
        $purger = new ORMPurger($this->doctrine->getManager());
        $purger->setPurgeMode(ORMPurger::PURGE_MODE_TRUNCATE);
        try {
            $purger->purge();
        } catch (\Exception $e) {
            $schemaTool = new SchemaTool($this->doctrine->getManager());
            $schemaTool->createSchema($this->doctrine->getManager()->getMetadataFactory()->getAllMetadata());
        }
    }

    /**
     * @Given I have a valid token
     */
    public function iHaveAValidToken(): void
    {
        $this->passwordTokenManager->createPasswordToken($this->createUser());
    }

    /**
     * @Given I have an expired token
     */
    public function iHaveAnExpiredToken(): void
    {
        $this->passwordTokenManager->createPasswordToken($this->createUser(), new \DateTime('-1 minute'));
    }

    /**
     * @When I reset my password
     * @When I reset my password with my :propertyName ":value"
     *
     * @param string $propertyName
     * @param string $value
     */
    public function IResetMyPassword($propertyName = 'email', $value = 'john.doe@example.com'): void
    {
        $this->createUser();

        $this->client->enableProfiler();
        $this->client->request(
            'POST',
            '/api/forgot-password/',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            sprintf(<<<'JSON'
{
    "%s": "%s"
}
JSON
                , $propertyName, $value)
        );
    }

    /**
     * @Then I should receive an email
     */
    public function iShouldReceiveAnEmail(): void
    {
        Assert::assertTrue(
            $this->client->getResponse()->isSuccessful(),
            sprintf('Response is not valid: got %d', $this->client->getResponse()->getStatusCode())
        );
        Assert::assertEmpty($this->client->getResponse()->getContent());

        /** @var MessageDataCollector $mailCollector */
        $mailCollector = $this->client->getProfile()->getCollector('mailer');
        $messages = $mailCollector->getEvents()->getMessages();
        Assert::assertCount(1, $messages, 'No email has been sent');

        /** @var \Symfony\Component\Mime\Email $message */
        $message = $messages[0];
        Assert::assertInstanceOf(RawMessage::class, $message);
        Assert::assertEquals('Réinitialisation de votre mot de passe', $message->getSubject());
        Assert::assertEquals('no-reply@example.com', $message->getFrom()[0]->getAddress());
        Assert::assertEquals('john.doe@example.com', $message->getTo()[0]->getAddress());
        Assert::assertMatchesRegularExpression('/http:\/\/www\.example\.com\/api\/forgot-password\/(.*)/', $message->getHtmlBody());
    }

    /**
     * @When the page should not be found
     */
    public function thePageShouldNotBeFound(): void
    {
        Assert::assertTrue(
            $this->client->getResponse()->isNotFound(),
            sprintf('Response is not valid: got %d', $this->client->getResponse()->getStatusCode())
        );
    }

    /**
     * @Then the response should be empty
     */
    public function theResponseShouldBeEmpty(): void
    {
        Assert::assertTrue(
            $this->client->getResponse()->isEmpty(),
            sprintf('Response is not valid: got %d', $this->client->getResponse()->getStatusCode())
        );
    }

    /**
     * @When the request should be invalid with message :message
     *
     * @param string $message
     */
    public function theRequestShouldBeInvalidWithMessage($message): void
    {
        Assert::assertEquals(
            400,
            $this->client->getResponse()->getStatusCode(),
            sprintf('Response is not valid: got %d', $this->client->getResponse()->getStatusCode())
        );
        Assert::assertJson($this->client->getResponse()->getContent());
        Assert::assertJsonStringEqualsJsonString(sprintf(<<<'JSON'
{
    "message": "%s"
}
JSON
            , str_ireplace('"', '\"', $message)
        ), $this->client->getResponse()->getContent()
        );
    }

    /**
     * @When I reset my password using invalid email address
     */
    public function iResetMyPasswordUsingInvalidEmailAddress(): void
    {
        $this->client->request(
            'POST',
            '/api/forgot-password/',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            <<<'JSON'
{
    "email": "foo@example.com"
}
JSON
        );
    }

    /**
     * @When I reset my password using no parameter
     */
    public function iResetMyPasswordUsingNoParameter(): void
    {
        $this->client->request('POST', '/api/forgot-password/');
    }

    /**
     * @When I update my password
     */
    public function iUpdateMyPassword(): void
    {
        $token = $this->passwordTokenManager->createPasswordToken($this->createUser());

        $this->client->request(
            'POST',
            sprintf('/api/forgot-password/%s', $token->getToken()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            <<<'JSON'
{
    "ignoreMe": "bar",
    "password": "foo"
}
JSON
        );
    }

    /**
     * @Then the password should have been updated
     */
    public function thePasswordShouldHaveBeenUpdated(): void
    {
        $user = $this->doctrine->getManager()->getRepository(User::class)->findOneBy(['username' => 'JohnDoe']);

        Assert::assertNotNull($user, 'Unable to retrieve User object.');
        Assert::assertEquals('foo', $user->getPassword(), sprintf('User password hasn\'t be updated, expected "foo", got "%s".', $user->getPassword()));
    }

    /**
     * @When I update my password using no password
     */
    public function iUpdateMyPasswordUsingNoPassword(): void
    {
        $token = $this->passwordTokenManager->createPasswordToken($this->createUser());

        $this->client->request('POST', sprintf('/api/forgot-password/%s', $token->getToken()));
    }

    /**
     * @When I update my password using an invalid token
     */
    public function iUpdateMyPasswordUsingAnInvalidToken(): void
    {
        $this->client->request(
            'POST',
            '/api/forgot-password/12345',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            <<<'JSON'
{
    "password": "foo"
}
JSON
        );
    }

    /**
     * @When I update my password using an expired token
     */
    public function iUpdateMyPasswordUsingAnExpiredToken(): void
    {
        $token = $this->passwordTokenManager->createPasswordToken($this->createUser(), new \DateTime('-1 minute'));

        $this->client->request(
            'POST',
            sprintf('/api/forgot-password/%s', $token->getToken()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            <<<'JSON'
{
    "password": "foo"
}
JSON
        );
    }

    /**
     * @When I get a password token
     */
    public function iGetAPasswordToken(): void
    {
        $token = $this->passwordTokenManager->createPasswordToken($this->createUser());
        $token->setToken('d7xtQlJVyN61TzWtrY6xy37zOxB66BqMSDXEbXBbo2Mw4Jjt9C');
        $this->doctrine->getManager()->persist($token);
        $this->doctrine->getManager()->flush();

        $this->client->request('GET', sprintf('/api/forgot-password/%s', $token->getToken()));
    }

    /**
     * @Then I should get a password token
     */
    public function iShouldGetAPasswordToken(): void
    {
        Assert::assertTrue(
            $this->client->getResponse()->isSuccessful(),
            sprintf('Response is not valid: got %d', $this->client->getResponse()->getStatusCode())
        );
        Assert::assertJson($this->client->getResponse()->getContent());
    }

    /**
     * @When I get a password token using an expired token
     */
    public function iGetAPasswordTokenUsingAnExpiredToken(): void
    {
        $token = $this->passwordTokenManager->createPasswordToken($this->createUser(), new \DateTime('-1 minute'));

        $this->client->request('GET', sprintf('/api/forgot-password/%s', $token->getToken()));
    }

    /**
     * @When I get the OpenApi documentation
     */
    public function iGetOpenApiDocumentation(): void
    {
        $exitCode = $this->application->doRun(new ArgvInput(['behat-test', 'api:openapi:export']), $this->output);
        Assert::assertEquals(0, $exitCode, sprintf('Unable to run "api:openapi:export" command: got %s exit code.', $exitCode));
    }

    /**
     * @Then I should get an OpenApi documentation updated
     */
    public function iShouldGetAnOpenApiDocumentationUpdated(): void
    {
        $output = $this->output->fetch();
        Assert::assertJson($output);

        $openApi = json_decode($output, true);
        Assert::assertEquals([
            '/api/forgot-password/' => [
                'ref' => 'ForgotPassword',
                'post' => [
                    'operationId' => 'postForgotPassword',
                    'tags' => ['Forgot password'],
                    'responses' => [
                        204 => [
                            'description' => 'Valid email address, no matter if user exists or not',
                        ],
                        400 => [
                            'description' => 'Missing email parameter or invalid format',
                        ],
                    ],
                    'summary' => 'Generates a token and send email',
                    'description' => '',
                    'parameters' => [],
                    'requestBody' => [
                        'description' => 'Request a new password',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ForgotPassword:request',
                                ],
                            ],
                        ],
                        'required' => true,
                    ],
                    'deprecated' => false,
                ],
                'parameters' => [],
            ],
            '/api/forgot-password/{tokenValue}' => [
                'ref' => 'ForgotPassword',
                'get' => [
                    'operationId' => 'getForgotPassword',
                    'tags' => ['Forgot password'],
                    'responses' => [
                        200 => [
                            'description' => 'Authenticated user',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        '$ref' => '#/components/schemas/ForgotPassword:validate',
                                    ],
                                ],
                            ],
                        ],
                        404 => [
                            'description' => 'Token not found or expired',
                        ],
                    ],
                    'summary' => 'Validates token',
                    'description' => '',
                    'parameters' => [
                        [
                            'name' => 'tokenValue',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'deprecated' => false,
                ],
                'post' => [
                    'operationId' => 'postForgotPasswordToken',
                    'tags' => ['Forgot password'],
                    'responses' => [
                        204 => [
                            'description' => 'Email address format valid, no matter if user exists or not',
                        ],
                        400 => [
                            'description' => 'Missing password parameter',
                        ],
                        404 => [
                            'description' => 'Token not found',
                        ],
                    ],
                    'summary' => 'Validates token',
                    'description' => '',
                    'parameters' => [
                        [
                            'name' => 'tokenValue',
                            'in' => 'path',
                            'required' => true,
                            'schema' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                    'requestBody' => [
                        'description' => 'Reset password',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    '$ref' => '#/components/schemas/ForgotPassword:reset',
                                ],
                            ],
                        ],
                        'required' => true,
                    ],
                    'deprecated' => false,
                ],
                'parameters' => [],
            ],
        ], $openApi['paths']);
        Assert::assertEquals([
            'schemas' => [
                'ForgotPassword:reset' => [
                    'type' => 'object',
                    'required' => ['password'],
                    'properties' => [
                        'password' => [
                            'type' => 'string',
                        ],
                    ],
                ],
                'ForgotPassword:validate' => [
                    'type' => 'object',
                ],
                'ForgotPassword:request' => [
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => [
                        'email' => [
                            'oneOf' => [
                                ['type' => 'string'],
                                ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [],
            'parameters' => [],
            'examples' => [],
            'requestBodies' => [],
            'headers' => [],
            'securitySchemes' => [],
        ], $openApi['components']);
    }

    /**
     * @return User
     */
    private function createUser()
    {
        $user = new User();
        $user->setEmail('john.doe@example.com');
        $user->setUsername('JohnDoe');
        $user->setPassword('password');
        $this->doctrine->getManager()->persist($user);
        $this->doctrine->getManager()->flush();

        return $user;
    }
}
