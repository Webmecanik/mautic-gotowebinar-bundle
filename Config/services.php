<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MauticCitrixBundle\Integration\GotoMeetingConfiguration;
use MauticPlugin\MauticCitrixBundle\Integration\GotoWebinarConfiguration;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator) {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = ['Integration/Auth', 'Docs', 'Event', 'Entity'];

    $services->load('MauticPlugin\\MauticCitrixBundle\\EventListener\\', '../EventListener')
        ->tag('kernel.event_subscriber');

    $services->load('MauticPlugin\\MauticCitrixBundle\\Entity\\', '../Entity/*Repository.php');

    $services->load('MauticPlugin\\MauticCitrixBundle\\', '../')
        ->exclude('../{'.implode(',', [...MauticCoreExtension::DEFAULT_EXCLUDES, ...$excludes]).'}');

    $services->alias('mautic.gotowebinar.configuration', GotoWebinarConfiguration::class);
};
