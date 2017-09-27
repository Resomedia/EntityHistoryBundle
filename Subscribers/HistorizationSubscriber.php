<?php

namespace Resomedia\EntityHistoryBundle\Subscribers;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Resomedia\EntityHistoryBundle\Services\HistorizationManager;

class HistorizationSubscriber implements EventSubscriber
{
    /**
     * @var HistorizationManager $manager
     */
    protected $manager;

    /**
     * @var $configs
     */
    protected $configs;

    /**
     * HistorizationSubscriber constructor.
     * @param HistorizationManager $historizationManager
     */
    public function __construct(HistorizationManager $historizationManager, $configs)
    {
        $this->manager = $historizationManager;
        $this->configs = $configs;
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

    public function preFlush(PreFlushEventArgs $preFlushEventArgs) {
        $unitOfWork = $preFlushEventArgs->getEntityManager()->getUnitOfWork();
        foreach($unitOfWork->getIdentityMap() as $entityMap) {
            foreach($entityMap as $entity) {
                if ($this->manager->hasHistoryAnnotation($entity) != null) {
                    $rev = $this->manager->historizationEntity($entity);
                    $preFlushEventArgs->getEntityManager()->persist($rev);

                }
            }
        }
    }
}