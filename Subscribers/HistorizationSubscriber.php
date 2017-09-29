<?php

namespace Resomedia\EntityHistoryBundle\Subscribers;

use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Util\ClassUtils;
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
        $entities = $this->manager->historizableEntities($allEntities);
        $tabCompare = array();
        foreach ($entities as $entity) {
            //gestion des doublons
            if(strstr(get_class($entity), "Proxies")) {
                $className = ClassUtils::getClass($entity);
            } else {
                $className = get_class($entity);
            }
            if (array_key_exists($className, $tabCompare)) {
                if (!in_array($entity->getId(), $tabCompare[$className])) {
                    $tabCompare[$className][] = $entity->getId();
                    $rev = $this->manager->historizationEntity($entity);
                    $em->persist($rev);
                }
            } else {
                $tabCompare[$className] = array($entity->getId());
                $rev = $this->manager->historizationEntity($entity);
                $em->persist($rev);
            }
        }
    }
}