<?php

namespace MauticPlugin\MauticCitrixBundle\Api;

use Mautic\PluginBundle\Exception\ApiErrorException;
use MauticPlugin\MauticCitrixBundle\Integration\CitrixAbstractIntegration;
use Psr\Http\Message\ResponseInterface;

class CitrixApi
{
    /**
     * @var CitrixAbstractIntegration
     */
    protected $integration;

    /**
     * CitrixApi constructor.
     */
    public function __construct(CitrixAbstractIntegration $integration)
    {
        $this->integration = $integration;
    }

    /**
     * @param string $operation
     * @param string $route
     * @param bool   $refreshToken
     *
     * @return mixed|string
     *
     * @throws ApiErrorException
     */
    protected function _request($operation, array $settings, $route = 'rest', $refreshToken = true)
    {
        $message = null;
        $requestSettings = [
            'encode_parameters'   => 'json',
            'return_raw'          => 'true', // needed to get the HTTP status code in the response
        ];

        if (array_key_exists('requestSettings', $settings) && is_array($settings['requestSettings'])) {
            $requestSettings = array_merge($requestSettings, $settings['requestSettings']);
        }

        $url = sprintf(
            '%s/%s/%s/%s',
            $this->integration->getApiUrl(),
            $settings['module'],
            $route,
            $operation
        );
        /** @var ResponseInterface|array $request */
        $request = $this->integration->makeRequest(
            $url,
            $settings['parameters'],
            $settings['method'],
            $requestSettings
        );
        if ($request instanceof ResponseInterface) {
            $status  = $request->getStatusCode();
            $message = '';

            // Try refresh access_token with refresh_token (https://goto-developer.logmeininc.com/how-use-refresh-tokens)
            if ($refreshToken && $this->isInvalidTokenFromReponse($request)) {
                $error = $this->integration->authCallback(['use_refresh_token' => true]);
                if (!$error) {
                    // keys changes, load new integration object
                    return $this->_request($operation, $settings, $route, false);
                }
            }
        } else {
            $status = $request['error']['code'] ?? 400;
        }

        switch ($status) {
            case 200:
            case 201:
            case 204:
                // PUT/DELETE ok
                break;
            case 400:
                $message = 'Bad request';
                break;
            case 403:
                $message = 'Token invalid';
                break;
            case 404:
                $message = 'The requested object does not exist';
                break;
            case 409:
                $message = 'The user is already registered';
                break;
            default:
                $message = $request['error']['message'] ?? 'unknown error';
                break;
        }

        if ('' !== $message) {
            throw new ApiErrorException($message);
        }

        return $this->integration->parseCallbackResponse($request->getBody());
    }

    /**
     * @return bool
     */
    private function isInvalidTokenFromReponse(ResponseInterface $request)
    {
        $responseData = $this->integration->parseCallbackResponse($request->getBody());
        return isset($responseData['int_err_code']) && 'InvalidToken' == $responseData['int_err_code'];
    }
}