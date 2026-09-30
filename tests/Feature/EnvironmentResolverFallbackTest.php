<?php

use App\Client\Connector;
use App\Client\Resources\Applications\ListApplicationsRequest;
use App\Client\Resources\Environments\AddEnvironmentVariablesRequest;
use App\Client\Resources\Environments\GetEnvironmentRequest;
use App\Client\Resources\Environments\ListEnvironmentsRequest;
use App\Client\Resources\Meta\GetOrganizationRequest;
use App\ConfigRepository;
use App\Dto\Application;
use App\Exceptions\CommandExitException;
use App\Git;
use App\LocalConfig;
use App\Resolvers\EnvironmentResolver;
use Laravel\Prompts\Prompt;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function () {
    Prompt::fake();

    $this->mockGit = Mockery::mock(Git::class);
    $this->mockGit->shouldReceive('remoteRepo')->andReturn('')->byDefault();
    $this->mockGit->shouldReceive('currentBranch')->andReturn('main')->byDefault();
    $this->app->instance(Git::class, $this->mockGit);

    $this->mockConfig = Mockery::mock(ConfigRepository::class);
    $this->mockConfig->shouldReceive('apiTokens')->andReturn(collect(['test-api-token']));
    $this->app->instance(ConfigRepository::class, $this->mockConfig);

    $this->localConfig = Mockery::mock(LocalConfig::class);
    $this->localConfig->shouldReceive('get')->with('organization_id')->andReturn(null)->byDefault();
    $this->localConfig->shouldReceive('applicationId')->andReturn(null)->byDefault();
    $this->localConfig->shouldReceive('environmentId')->andReturn(null)->byDefault();
    $this->localConfig->shouldReceive('path')->andReturn('/project/.cloud/config.json')->byDefault();
    $this->app->instance(LocalConfig::class, $this->localConfig);

    $environment = createEnvironmentResponse([
        'attributes' => ['branch' => 'main'],
    ]);

    MockClient::global([
        GetOrganizationRequest::class => MockResponse::make(organizationResponse(), 200),
        ListApplicationsRequest::class => MockResponse::make([
            'data' => [createApplicationResponse()],
            'included' => [
                organizationResponse()['data'],
                $environment,
            ],
            'links' => ['next' => null],
        ], 200),
        ListEnvironmentsRequest::class => MockResponse::make([
            'data' => [$environment],
            'links' => ['next' => null],
        ], 200),
        GetEnvironmentRequest::class => fn (PendingRequest $request) => str_ends_with($request->getUrl(), '/env-1')
            ? MockResponse::make(['data' => $environment], 200)
            : MockResponse::make(['message' => 'Not found'], 404),
        AddEnvironmentVariablesRequest::class => MockResponse::make(['data' => $environment], 200),
    ]);
});

afterEach(function () {
    MockClient::destroyGlobal();
});

it('fails instead of falling back when an explicit environment does not resolve', function (string $identifier) {
    $this->artisan('environment:variables', [
        'environment' => $identifier,
        '--action' => 'set',
        '--key' => 'APP_NAME',
        '--value' => 'Cloud',
    ])->assertFailed();

    MockClient::global()->assertNotSent(AddEnvironmentVariablesRequest::class);
})->with(['env-missing', 'missing']);

it('fails instead of falling back when the configured environment does not resolve', function () {
    $this->localConfig->shouldReceive('environmentId')->andReturn('env-missing');

    $this->artisan('environment:variables', [
        '--action' => 'set',
        '--key' => 'APP_NAME',
        '--value' => 'Cloud',
    ])->assertFailed();

    MockClient::global()->assertNotSent(AddEnvironmentVariablesRequest::class);
});

it('reports the existing error when an environment does not resolve', function () {
    Prompt::theme('default');

    $application = Application::createFromResponse([
        'data' => createApplicationResponse(),
        'included' => [createEnvironmentResponse()],
    ]);
    $resolver = (new EnvironmentResolver(new Connector('test-api-token'), $this->localConfig, true))
        ->withApplication($application);

    expect(fn () => $resolver->from('env-missing'))
        ->toThrow(CommandExitException::class);

    Prompt::assertOutputContains('Unable to resolve environment');
});

it('names the config file when the configured environment does not resolve', function () {
    Prompt::theme('default');

    $this->localConfig->shouldReceive('environmentId')->andReturn('env-missing');

    $application = Application::createFromResponse([
        'data' => createApplicationResponse(),
        'included' => [createEnvironmentResponse()],
    ]);
    $resolver = (new EnvironmentResolver(new Connector('test-api-token'), $this->localConfig, true))
        ->withApplication($application);

    expect(fn () => $resolver->from())
        ->toThrow(CommandExitException::class);

    Prompt::assertOutputContains('Unable to resolve environment env-missing from /project/.cloud/config.json.');
});

it('resolves an environment from the current branch when no identifier is supplied', function () {
    $this->artisan('environment:variables', [
        '--action' => 'set',
        '--key' => 'APP_NAME',
        '--value' => 'Cloud',
    ])->assertSuccessful();

    MockClient::global()->assertSent(fn ($request) => $request instanceof AddEnvironmentVariablesRequest
        && $request->resolveEndpoint() === '/environments/env-1/variables');
});

it('still resolves a valid environment identifier', function (string $identifier) {
    $this->artisan('environment:variables', [
        'environment' => $identifier,
        '--action' => 'set',
        '--key' => 'APP_NAME',
        '--value' => 'Cloud',
    ])->assertSuccessful();

    MockClient::global()->assertSent(fn ($request) => $request instanceof AddEnvironmentVariablesRequest
        && $request->resolveEndpoint() === '/environments/env-1/variables');
})->with(['env-1', 'production']);
