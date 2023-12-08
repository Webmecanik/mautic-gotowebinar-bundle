<?php

declare(strict_types=1);

namespace MauticPlugin\MauticCitrixBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\MauticCitrixBundle\CitrixEvents;
use MauticPlugin\MauticCitrixBundle\Entity\CitrixEventTypes;
use MauticPlugin\MauticCitrixBundle\Form\Type\CitrixCampaignActionType;
use MauticPlugin\MauticCitrixBundle\Form\Type\CitrixCampaignEventType;
use MauticPlugin\MauticCitrixBundle\Helper\CitrixProducts;
use MauticPlugin\MauticCitrixBundle\Helper\CitrixServiceHelper;
use MauticPlugin\MauticCitrixBundle\Model\CitrixModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class CampaignSubscriber implements EventSubscriberInterface
{
    use CitrixRegistrationTrait;
    use CitrixStartTrait;

    public function __construct(
        private CitrixServiceHelper $serviceHelper,
        private CitrixModel $citrixModel,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private Environment $templating,
        private EmailModel $emailModel,
    ) {
    }

    public static function getSubscribedEvents()
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD       => ['onCampaignBuild', 0],
            CitrixEvents::ON_CITRIX_WEBINAR_EVENT   => ['onWebinarEvent', 0],
            CitrixEvents::ON_CITRIX_WEBINAR_ACTION  => ['onWebinarAction', 0],
        ];
    }

    /* Actions */

    public function onWebinarAction(CampaignExecutionEvent $event): void
    {
        $event->setResult($this->onCitrixAction(CitrixProducts::GOTOWEBINAR, $event));
    }

    /**
     * @param string $product
     */
    public function onCitrixAction($product, CampaignExecutionEvent $event): bool
    {
        if (!CitrixProducts::isValidValue($product)) {
            return false;
        }

        // get firstName, lastName and email from keys for sender email
        $config   = $event->getConfig();
        $criteria = $config['event-criteria-'.$product];
        /** @var array $list */
        $list     = $config[$product.'-list'];
        $actionId = 'citrix.action.'.$product;
        try {
            $productlist = $this->serviceHelper->getCitrixChoices($product);
            $products    = [];

            foreach ($list as $productId) {
                if (array_key_exists(
                    $productId,
                    $productlist
                )) {
                    $products[] = [
                        'productId'    => $productId,
                        'productTitle' => $productlist[$productId],
                    ];
                }
            }
            if (in_array($criteria, ['webinar_register', 'training_register'], true)) {
                $this->registerProduct($product, $event->getLead(), $products);
            } elseif (in_array($criteria, ['assist_screensharing', 'training_start', 'meeting_start'], true)) {
                $emailId = $config['template'] ?? null;
                $this->startProduct($product, $event->getLead(), $products, $emailId, $actionId);
            }
        } catch (\Exception $ex) {
            $this->logger->error('onCitrixAction - '.$product.': '.$ex->getMessage());
        }

        return true;
    }

    /* Events */

    public function onWebinarEvent(CampaignExecutionEvent $event): void
    {
        $event->setResult($this->onCitrixEvent(CitrixProducts::GOTOWEBINAR, $event));
    }

    /**
     * @param string $product
     *
     * @return bool
     *
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     */
    public function onCitrixEvent($product, CampaignExecutionEvent $event)
    {
        if (!CitrixProducts::isValidValue($product)) {
            return false;
        }

        $config   = $event->getConfig();
        $criteria = $config['event-criteria-'.$product];
        $list     = $config[$product.'-list'];
        $isAny    = in_array('ANY', $list, true);
        $email    = $event->getLead()->getEmail();

        if ('registeredToAtLeast' === $criteria) {
            $counter = $this->citrixModel->countEventsBy(
                $product,
                $email,
                CitrixEventTypes::REGISTERED,
                $isAny ? [] : $list
            );
        } elseif ('attendedToAtLeast' === $criteria) {
            $counter = $this->citrixModel->countEventsBy(
                $product,
                $email,
                CitrixEventTypes::ATTENDED,
                $isAny ? [] : $list
            );
        } else {
            return false;
        }

        return $counter > 0;
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $activeProducts = array_filter(CitrixProducts::toArray(), [$this->serviceHelper, 'isIntegrationAuthorized']);

        if ([] === $activeProducts) {
            return;
        }

        $eventNames = [CitrixProducts::GOTOWEBINAR  => CitrixEvents::ON_CITRIX_WEBINAR_EVENT];

        $actionNames = [CitrixProducts::GOTOWEBINAR  => CitrixEvents::ON_CITRIX_WEBINAR_ACTION];

        foreach ($activeProducts as $product) {
            $event->addCondition(
                'citrix.event.'.$product,
                [
                    'label'           => 'plugin.citrix.campaign.event.'.$product.'.label',
                    'formType'        => CitrixCampaignEventType::class,
                    'formTypeOptions' => [
                        'attr' => [
                            'data-product' => $product,
                        ],
                    ],
                    'eventName'      => $eventNames[$product],
                    'channel'        => 'citrix',
                    'channelIdField' => $product.'-list',
                ]
            );

            $event->addAction(
                'citrix.action.'.$product,
                [
                    'label'           => 'plugin.citrix.campaign.action.'.$product.'.label',
                    'formType'        => CitrixCampaignActionType::class,
                    'formTypeOptions' => [
                        'attr' => [
                            'data-product' => $product,
                        ],
                    ],
                    'eventName'      => $actionNames[$product],
                    'channel'        => 'citrix',
                    'channelIdField' => $product.'-list',
                ]
            );
        }
    }
}
