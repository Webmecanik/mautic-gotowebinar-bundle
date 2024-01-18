<?php

namespace MauticPlugin\MauticCitrixBundle\Integration\Auth;

use Mautic\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\Credentials\AccessTokenInterface;
use Mautic\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\Credentials\CodeInterface;
use Mautic\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\Credentials\CredentialsInterface;
use Mautic\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\Credentials\RedirectUriInterface;
use Mautic\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\Credentials\RefreshTokenInterface;
use Mautic\IntegrationsBundle\Auth\Provider\Oauth2TwoLegged\Credentials\StateInterface;

class OAuth2ThreeLeggedCredentials implements AccessTokenInterface, CodeInterface, StateInterface, CredentialsInterface, RefreshTokenInterface, RedirectUriInterface
{
    public function __construct(
        private ?string $client_id = null,
        private ?string $client_secret = null,
        private ?string $access_token = null,
        private ?string $refresh_token = null,
        private ?string $token_url = null,
        private ?string $base_uri = null,
        private ?string $code = null,
        private ?string $state = null,
        private ?string $redirect_uri = null,
        private ?int $expires_at = null
    ) {
    }

    public function getAccessToken(): ?string
    {
        return $this->access_token;
    }

    public function getAccessTokenExpiry(): ?\DateTimeImmutable
    {
        // Not implemented as oauth middleware takes care of it. but I will.
        return (new \DateTimeImmutable())->setTimestamp($this->expires_at ?? 0);
    }

    public function getAuthorizationUrl(): string
    {
        return $this->constructAuthorizationUrl();
    }

    public function getBaseUri(): ?string
    {
        return $this->base_uri;
    }

    public function getClientId(): ?string
    {
        return $this->client_id;
    }

    public function getClientSecret(): ?string
    {
        return $this->client_secret;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function getExpiresAt(): ?int
    {
        return $this->expires_at;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refresh_token;
    }

    public function getRedirectUri(): string
    {
        return $this->redirect_uri;
    }

    public function getState(): ?string
    {
        return $this->state;
    }

    public function getTokenUrl(): string
    {
        return $this->token_url;
    }

    // End of Getters

    private function constructAuthorizationUrl(): string
    {
        return $this->base_uri ? $this->base_uri.'/oauth2/authorize' : '';
    }

    // Setters
    public function setAccessToken(?string $access_token): self
    {
        $this->access_token = $access_token;

        return $this;
    }

    public function setBaseUri(?string $base_uri): self
    {
        $this->base_uri = $base_uri;

        return $this;
    }

    public function setCode(?string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function setRefreshToken(?string $refresh_token): self
    {
        $this->refresh_token = $refresh_token;

        return $this;
    }

    public function setState(?string $state): self
    {
        $this->state = $state;

        return $this;
    }
    // End of Setters
}
