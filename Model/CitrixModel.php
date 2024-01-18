<?php

declare(strict_types=1);

namespace MauticPlugin\MauticCitrixBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticCitrixBundle\CitrixEvents;
use MauticPlugin\MauticCitrixBundle\Entity\CitrixEvent;
use MauticPlugin\MauticCitrixBundle\Entity\CitrixEventRepository;
use MauticPlugin\MauticCitrixBundle\Entity\CitrixEventTypes;
use MauticPlugin\MauticCitrixBundle\Event\CitrixEventUpdateEvent;
use MauticPlugin\MauticCitrixBundle\Helper\CitrixProducts;
use MauticPlugin\MauticCitrixBundle\Helper\CitrixServiceHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Class CitrixModel.
 */
class CitrixModel extends FormModel
{
    public function __construct(
        EntityManagerInterface $em,
        CorePermissions $security,
        EventDispatcherInterface $dispatcher,
        UrlGeneratorInterface $router,
        Translator $translator,
        UserHelper $userHelper,
        LoggerInterface $mauticLogger,
        CoreParametersHelper $coreParametersHelper,
        protected CitrixServiceHelper $serviceHelper,
    ) {
        parent::__construct(
            $em,
            $security,
            $dispatcher,
            $router,
            $translator,
            $userHelper,
            $mauticLogger,
            $coreParametersHelper
        );
    }

    public function getRepository(): CitrixEventRepository
    {
        return $this->em->getRepository(CitrixEvent::class);
    }

    /**
     * @param string $product
     * @param string $email
     * @param string $eventName
     * @param string $eventDesc
     * @param Lead   $lead
     * @param string $eventType
     * @param string $joinURL
     *
     * @throws \Doctrine\ORM\ORMInvalidArgumentException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \InvalidArgumentException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    public function addEvent($product, $email, $eventName, $eventDesc, $eventType, $lead, \DateTime $eventDate = null, $joinURL = null): void
    {
        if (!CitrixProducts::isValidValue($product) || !CitrixEventTypes::isValidValue($eventType)) {
            $this->logger->error('addEvent: incorrect data');

            return;
        }
        $citrixEvent = new CitrixEvent();
        $citrixEvent->setProduct($product);
        $citrixEvent->setEmail($email);
        $citrixEvent->setEventName($eventName);
        $citrixEvent->setEventDesc($eventDesc);
        $citrixEvent->setEventType($eventType);
        $citrixEvent->setLead($lead);

        if ($eventDate instanceof \DateTime) {
            $citrixEvent->setEventDate($eventDate);
        }

        if (null !== $joinURL) {
            $citrixEvent->setEventDesc($eventDesc.'_!'.$joinURL);
        }

        $this->em->persist($citrixEvent);
        $this->em->flush();
    }

    /**
     * @param string $product
     * @param string $email
     *
     * @return array
     */
    public function getEventsByLeadEmail($product, $email)
    {
        if (!CitrixProducts::isValidValue($product)) {
            return []; // is not a valid citrix product
        }

        return $this->getRepository()->findByEmail($product, $email);
    }

    /**
     * @param string $product
     * @param string $eventName
     * @param string $eventType
     *
     * @return array
     */
    public function getEmailsByEvent($product, $eventName, $eventType)
    {
        if (!CitrixProducts::isValidValue($product) || !CitrixEventTypes::isValidValue($eventType)) {
            return []; // is not a valid citrix product
        }
        $citrixEvents = $this->getRepository()->findBy(
            [
                'product'   => $product,
                'eventName' => $eventName,
                'eventType' => $eventType,
            ]
        );

        $emails = [];
        if ([] !== $citrixEvents) {
            $emails = array_map(
                fn (CitrixEvent $citrixEvent) => $citrixEvent->getEmail(),
                $citrixEvents
            );
        }

        return $emails;
    }

    /**
     * @param string $product
     *
     * @return array
     */
    public function getDistinctEventNames($product)
    {
        if (!CitrixProducts::isValidValue($product)) {
            return []; // is not a valid citrix product
        }

        $dql = sprintf(
            "SELECT DISTINCT(c.eventName) FROM MauticCitrixBundle:CitrixEvent c WHERE c.product='%s'",
            $product
        );
        $query = $this->em->createQuery($dql);
        $items = $query->getResult();

        return array_map(
            fn ($item) => array_pop($item),
            $items
        );
    }

    /**
     * @param string $product
     *
     * @return array
     */
    public function getDistinctEventNamesDesc($product)
    {
        if (!CitrixProducts::isValidValue($product)) {
            return []; // is not a valid citrix product
        }
        $dql = sprintf(
            "SELECT DISTINCT c.eventName, c.eventDesc FROM MauticCitrixBundle:CitrixEvent c WHERE c.product='%s'",
            $product
        );
        $query  = $this->em->createQuery($dql);
        $items  = $query->getResult();
        $result = [];
        foreach ($items as $item) {
            $eventDesc = $item['eventDesc'];
            // strip joinUrl if exists
            $pos = strpos($eventDesc, '_!');
            if (false !== $pos) {
                $eventDesc = substr($eventDesc, 0, $pos);
            }
            // filter events with same id
            $eventId = $item['eventName'];
            $pos     = strpos($eventId, '_#');
            $eventId = substr($eventId, $pos);
            foreach (array_keys($result) as $k) {
                if (str_contains($k, $eventId)) {
                    unset($result[$k]);
                }
            }
            $result[$item['eventName']] = $eventDesc;
        }

        return $result;
    }

    /**
     * @param string $product
     * @param string $email
     * @param string $eventType
     */
    public function countEventsBy($product, $email, $eventType, array $eventNames = []): int
    {
        if (!CitrixProducts::isValidValue($product) || !CitrixEventTypes::isValidValue($eventType)) {
            return 0; // is not a valid citrix product
        }
        $dql = 'SELECT COUNT(c.id) as cant FROM MauticCitrixBundle:CitrixEvent c '.
            ' WHERE c.product=:product and c.email=:email AND c.eventType=:eventType ';

        if ([] !== $eventNames) {
            $dql .= 'AND c.eventName IN (:eventNames)';
        }

        $query = $this->em->createQuery($dql);
        $query->setParameters([
            ':product'   => $product,
            ':email'     => $email,
            ':eventType' => $eventType,
        ]);
        if ([] !== $eventNames) {
            $query->setParameter(':eventNames', $eventNames);
        }

        return (int) $query->getResult()[0]['cant'];
    }

    /**
     * @param int  $count
     * @param null $output
     */
    public function syncEvent($product, $productId, $eventName, $eventDesc, &$count = 0, $output = null): void
    {
        // $registrants = CitrixHelper::getRegistrants($product, $productId);
        $registrants      = $this->serviceHelper->getRegistrants($product, $productId);
        $knownRegistrants = $this->getEmailsByEvent(
            $product,
            $eventName,
            CitrixEventTypes::REGISTERED
        );

        [$registrantsToAdd, $registrantsToDelete] = $this->filterEventContacts($registrants, $knownRegistrants);
        $count += $this->batchAddAndRemove(
            $product,
            $eventName,
            $eventDesc,
            CitrixEventTypes::REGISTERED,
            $registrantsToAdd,
            $registrantsToDelete,
            $output
        );
        unset($registrants, $knownRegistrants, $registrantsToAdd, $registrantsToDelete);

        // $attendees = CitrixHelper::getAttendees($product, $productId);
        $attendees      = $this->serviceHelper->getAttendees($product, $productId);
        $knownAttendees = $this->getEmailsByEvent(
            $product,
            $eventName,
            CitrixEventTypes::ATTENDED
        );

        [$attendeesToAdd, $attendeesToDelete] = $this->filterEventContacts($attendees, $knownAttendees);
        $count += $this->batchAddAndRemove(
            $product,
            $eventName,
            $eventDesc,
            CitrixEventTypes::ATTENDED,
            $attendeesToAdd,
            $attendeesToDelete,
            $output
        );
        unset($attendees, $knownAttendees, $attendeesToAdd, $attendeesToDelete);
    }

    /**
     * @param string $product
     * @param string $eventName
     * @param string $eventDesc
     * @param string $eventType
     *
     * @return int
     *
     * @throws \Doctrine\ORM\ORMInvalidArgumentException
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \InvalidArgumentException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
     */
    public function batchAddAndRemove(
        $product,
        $eventName,
        $eventDesc,
        $eventType,
        array $contactsToAdd = [],
        array $emailsToRemove = [],
        OutputInterface $output = null
    ) {
        if (!CitrixProducts::isValidValue($product) || !CitrixEventTypes::isValidValue($eventType)) {
            return 0;
        }

        $count       = 0;
        $newEntities = [];

        // Add events
        if ([] !== $contactsToAdd) {
            $searchEmails = array_keys($contactsToAdd);
            $leads        = array_change_key_case(
                $this->leadModel->getRepository()->getLeadsByFieldValue('email', $searchEmails, null, true),
                CASE_LOWER
            );

            foreach ($contactsToAdd as $email => $info) {
                if (!isset($leads[strtolower($email)])) {
                    $lead = (new Lead())
                        ->addUpdatedField('email', $info['email'])
                        ->addUpdatedField('firstname', $info['firstname'])
                        ->addUpdatedField('lastname', $info['lastname']);
                    $this->leadModel->saveEntity($lead);

                    $leads[strtolower($email)] = $lead;
                }

                $citrixEvent = new CitrixEvent();
                $citrixEvent->setProduct($product);
                $citrixEvent->setEmail($email);
                $citrixEvent->setEventName($eventName);
                $citrixEvent->setEventDesc($eventDesc);
                $citrixEvent->setEventType($eventType);
                $citrixEvent->setLead($leads[$email]);

                if (!empty($info['event_date'])) {
                    $citrixEvent->setEventDate($info['event_date']);
                }

                if (!empty($info['joinUrl'])) {
                    $citrixEvent->setEventDesc($eventDesc.'_!'.$info['joinUrl']);
                }

                $newEntities[] = $citrixEvent;

                if ($output instanceof \Symfony\Component\Console\Output\OutputInterface) {
                    $output->writeln(
                        ' + '.$email.' '.$eventType.' to '.
                        substr($citrixEvent->getEventName(), 0, 40).((strlen(
                            $citrixEvent->getEventName()
                        ) > 40) ? '...' : '.')
                    );
                }
                ++$count;
            }

            $this->getRepository()->saveEntities($newEntities);
        }

        // Delete events
        if ([] !== $emailsToRemove) {
            $citrixEvents = $this->getRepository()->findBy(
                [
                    'eventName' => $eventName,
                    'eventType' => $eventType,
                    'email'     => $emailsToRemove,
                    'product'   => $product,
                ]
            );
            $this->getRepository()->deleteEntities($citrixEvents);

            /** @var CitrixEvent $citrixEvent */
            foreach ($citrixEvents as $citrixEvent) {
                if ($output instanceof \Symfony\Component\Console\Output\OutputInterface) {
                    $output->writeln(
                        ' - '.$citrixEvent->getEmail().' '.$eventType.' from '.
                        substr($citrixEvent->getEventName(), 0, 40).((strlen(
                            $citrixEvent->getEventName()
                        ) > 40) ? '...' : '.')
                    );
                }
                ++$count;
            }
        }

        /** @var CitrixEvent $entity */
        foreach ($newEntities as $entity) {
            if ($this->dispatcher->hasListeners(CitrixEvents::ON_CITRIX_EVENT_UPDATE)) {
                $citrixEvent = new CitrixEventUpdateEvent($product, $eventName, $eventDesc, $eventType, $entity->getLead());
                $this->dispatcher->dispatch($citrixEvent, CitrixEvents::ON_CITRIX_EVENT_UPDATE);
                unset($citrixEvent);
            }
        }

        $this->em->clear(Lead::class);
        $this->em->clear(CitrixEvent::class);

        return $count;
    }

    /**
     * @return array
     */
    private function filterEventContacts($found, $known)
    {
        // Lowercase the emails to keep things consistent
        $known  = array_map('strtolower', $known);
        $delete = array_diff($known, array_map('strtolower', array_keys($found)));
        $add    = array_filter(
            $found,
            fn ($key) => !in_array(strtolower($key), $known),
            ARRAY_FILTER_USE_KEY
        );

        return [$add, $delete];
    }
}
