<?php

namespace Resomedia\EntityHistoryBundle\Services;

use Doctrine\ORM\EntityManager;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * Class HistorizationManager
 * @package Resomedia\EntityHistoryBundle\Services
 */
class HistorizationManager
{
    /**
     * @var string $user_property
     */
    protected $user_property;

    /**
     * @var string $class_audit
     */
    protected $class_audit;

    /**
     * @var EntityManager $em
     */
    protected $em;

    /**
     * @var array $configs
     */
    protected $configs;

    /**
     * @var mixed
     */
    protected $current_user;

    /**
     * HistorizationManager constructor.
     * @param EntityManager $entityManager
     * @param $userProperty
     * @param $classAudit
     * @param $tokenStorage
     * @param $EntityConfigs
     */
    public function __construct(EntityManager $entityManager, $userProperty, $classAudit, TokenStorage $tokenStorage, $EntityConfigs)
    {
        $this->em = $entityManager;
        $this->class_audit = $classAudit;
        $this->user_property = $userProperty;
        $this->current_user = $tokenStorage->getToken()->getUser();
        $this->configs = $EntityConfigs;
    }

    public function historizationEntity($entity) {
        //on enregistre la version actuelle
    }

    public function compareEntityVersion($entity, $date = null, $lastVersionNumber = null) {
        //si date = null, on prend la dernière sinon la dernière version à cette date
        //si versionNumber != null, on prend la x ème version en partant de l'actuelle
    }
}