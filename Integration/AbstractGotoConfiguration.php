<?php

declare(strict_types=1);

namespace MauticPlugin\MauticCitrixBundle\Integration;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use kamermans\OAuth2\Persistence\ClosureTokenPersistence;
use kamermans\OAuth2\Persistence\TokenPersistenceInterface as KamermansTokenPersistenceInterface;
use Mautic\CacheBundle\Cache\CacheProvider;
use Mautic\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\Credentials\CredentialsInterface;
use Mautic\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\HttpFactory;
use Mautic\IntegrationsBundle\Auth\Support\Oauth2\ConfigAccess\ConfigTokenPersistenceInterface;
use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Exception\PluginNotConfiguredException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticCitrixBundle\Integration\Auth\OAuth2ThreeLeggedCredentials;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Cache\ItemInterface;

abstract class AbstractGotoConfiguration implements ConfigTokenPersistenceInterface
{
    private \Symfony\Component\Cache\Adapter\TagAwareAdapterInterface $cache;

    public function __construct(
        private IntegrationsHelper $helper,
        private RouterInterface    $router,
        private RequestStack       $requestStack,
        private HttpFactory        $httpFactory,
        CacheProvider              $cacheProvider,
    )
    {
        $this->cache = $cacheProvider->getCacheAdapter();
    }

    private ?array $userData = null;

    protected function getApiKeys(): array
    {    //  no need to expose, we serve everything necessary
        return $this->cleanUpKeys($this->getIntegrationEntity()->getApiKeys());
    }

    /**
     * This is previous configuration cleanup, most of them are no longer implemented as the API for Goto has changed
     * if really needed, new signer can be created to pass additional data but getUserData takes care of it.
     *
     * @param array<string> $keys
     *
     * @return array<string>
     */
    private function cleanUpKeys(array $keys): array
    {
        if (2 !== count(array_intersect_key($keys, array_flip(['expires', 'organizer_key'])))) {
            return $keys;
        }

        $preserveKeys = ['client_id', 'client_secret', 'access_token', 'refresh_token', 'expires_at'];

        return array_intersect_key($keys, array_flip($preserveKeys));
    }

    /**
     * @param CredentialsInterface|null $credentials
     * @return ClientInterface
     * @throws PluginNotConfiguredException
     */
    public function getHttpClient(?CredentialsInterface $credentials = null): ClientInterface
    {
        if (!$this->isConfigured()) {
            throw new PluginNotConfiguredException($this->getIntegrationName(true).' integration is not configured.');
        }

        return $this->httpFactory->getClient($credentials ?? $this->getCredentials(), $this);
    }

    public function isAuthorized(): bool
    {
        $requiredKeys = ['client_id', 'client_secret', 'access_token', 'refresh_token', 'expires_at'];
        $apiKeys      = $this->getApiKeys();

        $filteredKeys = array_filter($apiKeys, fn($key) => in_array($key, $requiredKeys) && isset($apiKeys[$key]), ARRAY_FILTER_USE_KEY);

        return count($filteredKeys) === count($requiredKeys);
    }

    public function isPublished(): bool
    {
        return $this->getIntegrationEntity()->isPublished();
    }

    public function isConfigured(): bool
    {
        $keys = $this->getApiKeys();

        return isset($keys['client_id']) && isset($keys['client_secret']);
    }

    /**
     * @throws IntegrationNotFoundException
     */
    public function getIntegrationEntity(): Integration
    {
        return $this->helper->getIntegration($this->getIntegrationName())->getIntegrationConfiguration();
    }

    public function getCredentials(): OAuth2ThreeLeggedCredentials
    {
        $apiKeys = $this->getApiKeys();

        $credentialsConfig = [
            'client_id'     => $apiKeys['client_id'] ?? null,
            'client_secret' => $apiKeys['client_secret'] ?? null,
            'access_token'  => $apiKeys['access_token'] ?? null,
            'refresh_token' => $apiKeys['refresh_token'] ?? null,
            'expires_at'    => $apiKeys['expires_at'] ?? null,
            'token_url'     => $this->getTokenUrl(),
            'base_uri'      => $this->getApiUrl(),
            'code'          => $apiKeys['code'] ?? null,
            'state'         => $apiKeys['state'] ?? null,
            'redirect_uri'  => $this->router->generate(
                'mautic_integration_auth_callback',
                ['integration' => $this->getIntegrationName()],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
        ];

        return new OAuth2ThreeLeggedCredentials(...$credentialsConfig);
    }

    public function getAuthenticationUrl(): string
    {
        return 'https://authentication.logmeininc.com';
    }

    public function getAuthorizationUrl(): string
    {
        $apiKeys = $this->getApiKeys();

        $state = $this->getAuthLoginState();

        return $this->getAuthenticationUrl().'/oauth/authorize'
            .'?client_id='.$apiKeys['client_id']
            .'&response_type=code'
            .'&redirect_uri='.urlencode($this->getCallbackUrl())
            .'&state='.$state;
    }

    public function getTokenUrl(): string
    {
        return $this->getAuthenticationUrl().'/oauth/token';
    }

    public function getCallbackUrl(): string
    {
        return $this->router->generate(
            'mautic_integration_auth_callback',
            ['integration' => $this->getIntegrationName()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    // Authentication related functions
    public function getAuthLoginState(): string
    {
        $state   = hash('sha1', uniqid((string)random_int(0, mt_getrandmax())));
        $session = $this->requestStack->getSession();

        $session->set($this->getIntegrationName().'_csrf_token', $state); // TODO not working
        $session->save();

        return $state;
    }

    public function getTokenPersistence(): KamermansTokenPersistenceInterface
    {
        return new ClosureTokenPersistence(
            function (array $keys) {    // Save tokens
                $standingKeys                  = $this->getApiKeys();
                $standingKeys['access_token']  = $keys['access_token'] ?? null;
                $standingKeys['refresh_token'] = $keys['refresh_token'] ?? null;
                $standingKeys['expires_at']    = $keys['expires_at'] ?? null;
                $configuration                 = $this->getIntegrationEntity();
                $configuration->setApiKeys($standingKeys);
                $this->helper->saveIntegrationConfiguration($configuration);
            },
            function (): ?array { // Restore tokens
                $keys = $this->getApiKeys();
                if ($keys['access_token'] ?? null !== null) {
                    return [
                        'access_token'  => $keys['access_token'] ?? null,
                        'refresh_token' => $keys['refresh_token'] ?? null,
                        'expires_at'    => $keys['expires_at'] ?? null,
                    ];
                }

                return null;
            },
            function (): bool { //  Delete tokens
                $standingKeys  = $this->getApiKeys();
                $configuration = $this->getIntegrationEntity();
                $configuration->setApiKeys($standingKeys);
                $this->helper->saveIntegrationConfiguration($configuration);

                return true;
            },
            function (): bool {
                $keys = $this->getApiKeys() ?? null;

                return $keys['access_token'] ?? null !== null;
            }
        );
    }

    /**
     * @return array<string,string|array<string,string>>
     *
     * @throws PluginNotConfiguredException
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function getUserData(bool $forceReload = false): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        if (null !== $this->userData && !$forceReload) {
            return $this->userData;
        }

        if ($forceReload) {
            $this->cache->delete('citrix_user_data');
        }

        return $this->cache->get('citrix_user_data', function (ItemInterface $item){
            $item->expiresAfter(3600);

            $response       = $this->getHttpClient()->get('/identity/v1/Users/me');
            $this->userData = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            return $this->userData;
        });
    }

    abstract protected function getIntegrationName(bool $displayName = false): string;
}
