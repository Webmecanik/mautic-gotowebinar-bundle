<?php

declare(strict_types=1);

namespace MauticPlugin\MauticCitrixBundle\Helper;

use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\PluginBundle\Exception\ApiErrorException;
use MauticPlugin\MauticCitrixBundle\Integration\GotoWebinarConfiguration;
use MauticPlugin\MauticCitrixBundle\Integration\GotowebinarIntegration;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class CitrixServiceHelper
{
    public function __construct(
        private GotoWebinarConfiguration $gotoWebinarConfiguration,
        private RouterInterface $router,
        private LoggerInterface $logger,
    ) {
    }

    public function isIntegrationAuthorized($productName): bool
    {
        try {
            $integration = $this->getIntegrationConfig($productName);

            return $integration->isPublished();
        } catch (IntegrationNotFoundException) {
        }

        return false;
    }

    //  this should be just proxy to the integration's client, for now it does the job

    /**
     * @return array<string,string>
     *
     * @throws ApiErrorException
     * @throws IntegrationNotFoundException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Mautic\IntegrationsBundle\Exception\PluginNotConfiguredException
     * @throws \GuzzleHttp\Exception\ClientException
     */
    public function getCitrixChoices(string $product, bool $onlyUpcoming = true): array
    {
        $config = $this->getIntegrationConfig($product);
        $client = $config->getHttpClient();

        $organizerKey = $config->getUserData()['id'] ?? null;

        if (null === $organizerKey) {
            throw new BadRequestHttpException('Unable to get user data!');
        }
        /**
         * webinar endpoint https://api.getgo.com/G2W/rest/v2/
         * https://api.getgo.com/G2W/rest/v2/organizers/{organizerKey}/webinars.
         */
        $onlyUpcoming = false;
        $endpointUri  = match ($product) {
            GotowebinarIntegration::GOTO_PRODUCT_NAME => '/organizers/{organizerKey}/webinars', // v2
        };

        $replacements = [
            '{organizerKey}' => $organizerKey,
        ];

        $endpointUri = str_replace(array_keys($replacements), array_values($replacements), $endpointUri);

        $dateFormat = match ($product) {
            GotowebinarIntegration::GOTO_PRODUCT_NAME => 'c',
        };

        $UTCZone = new \DateTimeZone('UTC');

        $parameters = match ($product) {
            GotowebinarIntegration::GOTO_PRODUCT_NAME => [
                'fromTime' => $onlyUpcoming ? (new \DateTimeImmutable('now', $UTCZone))->format($dateFormat) : (new \DateTimeImmutable('-10 year', $UTCZone))->format($dateFormat),
                'toTime'   => (new \DateTimeImmutable('+10 year', $UTCZone))->format($dateFormat),
            ],
        };

        // $response = $client->get($config->getApiUrl() . $endpointUri . '?' . http_build_query($parameters));
        $response = $client->get($config->getApiUrl().$endpointUri, ['query' => $parameters]);

        $parsed = $this->parseResponse($response);

        $list = match ($product) {
            GotowebinarIntegration::GOTO_PRODUCT_NAME => array_combine(
                array_column($parsed['_embedded']['webinars'] ?? [], 'webinarId'),
                array_column($parsed['_embedded']['webinars'] ?? [], 'subject'),
            ),
        };

        return $list;
    }

    //  Currently supports only webinar, training is not implemented
    public function registerToProduct($product, $productId, $email, $firstname, $lastname): string
    {
        $config = $this->getIntegrationConfig($product);
        $client = $config->getHttpClient();

        $params = match ($product) {
            GotowebinarIntegration::GOTO_PRODUCT_NAME => [
                'email'     => $email,
                'firstName' => $firstname,
                'lastName'  => $lastname,
            ],
            default => throw new BadRequestHttpException(sprintf('This action is not available for product %s.', $product))
        };

        $apiUrl = match ($product) {
            GotowebinarIntegration::GOTO_PRODUCT_NAME => '/webinars/'.$productId.'/registrants?resendConfirmation=true',
        };

        try {
            $response = $client->post($config->getApiUrl().$apiUrl, [
                'json' => $params,
            ]);
        } catch (\Exception $e) {
            throw new BadRequestHttpException($e->getMessage(), $e, $e->getCode());
        }

        if (!$response['joinUrl'] ?? null) {
            throw new BadRequestHttpException('Unable to register!');
        }

        $parsed = $this->parseResponse($response);

        return $parsed['joinUrl'];
    }

    public function getRegistrants(string $product, mixed $productId)
    {
        $config = $this->getIntegrationConfig($product);
        $client = $config->getHttpClient();

        $path = match ($product) {
            CitrixProducts::GOTOWEBINAR  => 'organizers/{organizerKey}/webinars/{webinarKey}/registrants',
            default                      => throw new \InvalidArgumentException("Invalid product: $product"),
        };

        $replacements = [
            '{organizerKey}' => $config->getUserData()['id'] ?? null,
            '{webinarKey}'   => $productId,
        ];

        $path = str_replace(array_keys($replacements), array_values($replacements), $path);

        $result = $client->request('GET', $path);

        return CitrixHelper::extractContacts($this->parseResponse($result));
    }

    public function getAttendees($product, $productId)
    {
        $config = $this->getIntegrationConfig($product);
        $client = $config->getHttpClient();

        //  the api v1 is a hack, the same endpoint on v2 returns 403, it works for registrants though
        $path = match ($product) {
            CitrixProducts::GOTOWEBINAR => $config->getApiV1Url().'organizers/{organizerKey}/webinars/{productKey}/attendees',
            default                     => throw new BadRequestHttpException(sprintf('This action is not available for product %s.', $product))
        };

        $replacements = [
            '{organizerKey}' => $config->getUserData()['id'] ?? null,
            '{productKey}'   => $productId,
        ];

        $path = str_replace(array_keys($replacements), array_values($replacements), $path);

        return CitrixHelper::extractContacts($this->parseResponse($client->get($path)));
    }

    public function mergeWithFutureEvents($choices, $product)
    {
        $events = $this->getCitrixChoices($product);
        foreach ($events as $key => $event) {
            foreach ($choices as $eventId => $eventname) {
                if (false !== strpos($eventId, '_#'.$key)) {
                    continue 2;
                }
            }
            $choices[CitrixHelper::getCleanString($event).'_#'.$key] = $event;
        }

        return $choices;
    }

    public function appendStartDateTimeToEventName($listType, array $eventNames = [])
    {
        $choices = $this->getCitrixChoices($listType, false);

        foreach ($eventNames as $eventName => $eventDesc) {
            // filter events with same id
            $eventDetails = explode('_#', $eventName);
            if (isset($eventDetails[1]) && isset($choices[$eventDetails[1]])) {
                $eventNames[$eventName] = $choices[$eventDetails[1]];
            }
        }

        return $eventNames;
    }

    private function getIntegrationConfig(string $productName)
    {
        return match ($productName) {
            GotowebinarIntegration::GOTO_PRODUCT_NAME => $this->gotoWebinarConfiguration,
            default                                   => throw new IntegrationNotFoundException(sprintf('Integration %s not found', $productName)),
        };
    }

    /**
     * TODO improve, check for more codes and handle more exceptions.
     *
     * @return array<string,string>|null
     *
     * @throws ApiErrorException
     */
    private function parseResponse(ResponseInterface $response)
    {
        try {
            $responseData = match ($response->getStatusCode()) {
                200     => json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR),
                204     => null,
                default => throw new ApiErrorException($response->getReasonPhrase(), $response->getStatusCode()),
            };
        } catch (\JsonException $e) {
            throw new ApiErrorException($e->getMessage(), $response->getStatusCode());
        }

        return $responseData;
    }
}
