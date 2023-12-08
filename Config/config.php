<?php

declare(strict_types=1);
return [
    'name'        => 'Goto Bundle',
    'description' => 'Goto products integration',
    'version'     => '1.0',
    'author'      => 'Webmecanik',
    'services'    => [
        'integrations' => [
            'mautic.integration.gotowebinar' => [
                'class'     => \MauticPlugin\MauticCitrixBundle\Integration\GotowebinarIntegration::class,
                'arguments' => [
                    'mautic.gotowebinar.configuration',
                    'request_stack',
                    'translator',
                    'monolog.logger.mautic',
                ],
                'tags' => [
                    'mautic.integration',
                    'mautic.basic_integration',
                ],
            ],
            'mautic.integration.gotowebinar.form_config' => [
                'class'     => \MauticPlugin\MauticCitrixBundle\Integration\Support\GotowebinarIntegrationFormSupport::class,
                'arguments' => [
                    'mautic.gotowebinar.configuration',
                    'request_stack',
                    'translator',
                    'monolog.logger.mautic',
                ],
                'tags' => ['mautic.config_integration'],
            ],
        ],
    ],
];
