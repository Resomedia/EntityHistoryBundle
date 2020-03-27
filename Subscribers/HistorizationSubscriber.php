<?php

namespace Resomedia\EntityHistoryBundle\Subscribers;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Resomedia\EntityHistoryBundle\Services\HistorizationManager;

/**
 * Class HistorizationSubscriber
 * @package Resomedia\EntityHistoryBundle\Subscribers
 */
class HistorizationSubscriber implements EventSubscriber
{
    /**
     * @var HistorizationManager $manager
     */
    protected $manager;

    /**
     * HistorizationSubscriber constructor.
     * @param HistorizationManager $historizationManager
     */
    public function __construct(HistorizationManager $historizationManager)
    {
        $this->manager = $historizationManager;
    }

    /**
     * @inheritdoc
     */
    public function getSubscribedEvents()
    {
        return array(
            Events::preFlush
        );
    }

    /**
     * @param PreFlushEventArgs $preFlushEventArgs
     */
    public function preFlush(PreFlushEventArgs $preFlushEventArgs) {
        $em = $preFlushEventArgs->getEntityManager();
        $unitOfWork = $em->getUnitOfWork();
        $allEntities = array();
        foreach($unitOfWork->getIdentityMap() as $entityMap) {
            foreach ($entityMap as $entity) {
                //here add entity only if the modifications concerned non ignore fields
                //but the entity will can be updated without the update have saved...
                $allEntities[] = $entity;
            }
        }
        $this->manager->historizationEntities($allEntities, $em);
    }
}