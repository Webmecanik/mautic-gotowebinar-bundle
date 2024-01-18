<?php

declare(strict_types=1);

namespace MauticPlugin\MauticCitrixBundle\Entity;

use Doctrine\ORM\Tools\Pagination\Paginator;
use Mautic\CoreBundle\Entity\CommonRepository;
use Mautic\LeadBundle\Entity\TimelineTrait;

class CitrixEventRepository extends CommonRepository
{
    use TimelineTrait;

    /**
     * Fetch the base event data from the database.
     *
     * @param string $product
     * @param string $eventType
     *
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function getEvents($product, $eventType, \DateTime $fromDate = null)
    {
        $q = $this->createQueryBuilder('c');

        $expr = $q->expr()->andX(
            $q->expr()->eq('c.product', ':product'),
            $q->expr()->eq('c.event_type', ':eventType')
        );

        if ($fromDate instanceof \DateTime) {
            $expr->add(
                $q->expr()->gte('c.event_date', ':fromDate')
            );
            $q->setParameter('fromDate', $fromDate);
        }

        $q->where($expr)
            ->setParameter('eventType', $eventType)
            ->setParameter('product', $product);

        return $q->getQuery()->getArrayResult();
    }

    /**
     * @param null $leadId
     *
     * @return array
     */
    public function getEventsForTimeline($product, $leadId = null, array $options = [])
    {
        $eventType = null;
        if (is_array($product)) {
            [$product, $eventType] = $product;
        }

        $query = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->from(MAUTIC_TABLE_PREFIX.'plugin_citrix_events', 'c')
            ->select('c.*');

        $query->where(
            $query->expr()->eq('c.product', ':product')
        )
            ->setParameter('product', $product);

        if ($eventType) {
            $query->andWhere(
                $query->expr()->eq('c.event_type', ':type')
            )
                ->setParameter('type', $eventType);
        }

        if ($leadId) {
            $query->andWhere('c.lead_id = '.(int) $leadId);
        }

        if (isset($options['search']) && $options['search']) {
            $query->andWhere($query->expr()->orX(
                $query->expr()->like('c.event_name', $query->expr()->literal('%'.$options['search'].'%')),
                $query->expr()->like('c.product', $query->expr()->literal('%'.$options['search'].'%'))
            ));
        }

        return $this->getTimelineResults($query, $options, 'c.event_name', 'c.event_date', [], ['event_date']);
    }

    /**
     * @param string $product
     * @param string $email
     *
     * @return array
     */
    public function findByEmail($product, $email)
    {
        return $this->findBy(
            [
                'product' => $product,
                'email'   => $email,
            ]
        );
    }

    /**
     * Get a list of entities.
     *
     * @return Paginator
     */
    public function getEntities(array $args = [])
    {
        $alias = $this->getTableAlias();

        $q = $this->_em
            ->createQueryBuilder()
            ->select($alias)
            ->from('MauticCitrixBundle:CitrixEvent', $alias, $alias.'.id');

        $args['qb'] = $q;

        return parent::getEntities($args);
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     *
     * @return array
     */
    protected function addCatchAllWhereClause($q, $filter): array
    {
        return $this->addStandardCatchAllWhereClause($q, $filter, ['c.product', 'c.email', 'c.eventType', 'c.eventName']);
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\DBAL\Query\QueryBuilder $q
     *
     * @return array
     */
    protected function addSearchCommandWhereClause($q, $filter): array
    {
        return $this->addStandardSearchCommandWhereClause($q, $filter);
    }

    /**
     * @return array
     */
    public function getSearchCommands(): array
    {
        return $this->getStandardSearchCommands();
    }

    /**
     * @return string
     */
    protected function getDefaultOrder(): array
    {
        return [
            [$this->getTableAlias().'.eventDate', 'ASC'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getTableAlias(): string
    {
        return 'c';
    }
}
