<?php

namespace Resomedia\EntityHistoryBundle\Services;

use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Embedded;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Resomedia\EntityHistoryBundle\Model\History;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;

/**
 * Class HistorizationManager
 * @package Resomedia\EntityHistoryBundle\Services
 */
class HistorizationManager
{
    const ENTITY_PROPERTY_ONE = 1;
    const ENTITY_PROPERTY_MANY = 2;
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
     * @var Reader
     */
    protected $reader;

    /**
     * HistorizationManager constructor.
     * @param EntityManager $entityManager
     * @param $userProperty
     * @param $classAudit
     * @param $authorizationChecker
     * @param $tokenStorage
     * @param $EntityConfigs
     * @param Reader $annReader
     */
    public function __construct(EntityManager $entityManager, $userProperty, $classAudit, AuthorizationChecker $authorizationChecker, TokenStorage $tokenStorage, $EntityConfigs, Reader $annReader)
    {
        $this->reader = $annReader;
        $this->em = $entityManager;
        $this->class_audit = $classAudit;
        $this->user_property = $userProperty;
        if ($authorizationChecker->isGranted('IS_AUTHENTICATED_ANONYMOUSLY')) {
            $this->current_user = History::ANONYMOUS;
        } else {
            $this->current_user = $tokenStorage->getToken()->getUser();
        }

        $this->configs = $EntityConfigs;
    }

    /**
     * register the actual version of entity
     * @param $entity
     */
    public function historizationEntity($entity) {
        $classAudit = $this->class_audit;
        $revision = new $classAudit();
        if ($this->current_user != History::ANONYMOUS) {
            $tabName = explode('_', $this->user_property);
            $methodName = '';
            foreach ($tabName as $tabNameExplode) {
                $methodName .= ucfirst($tabNameExplode);
            }
            $methodName = 'get' . $methodName;
            $revision->setUserProperty($this->current_user->$methodName());
        } else {
            $revision->setUserProperty($this->current_user);
        }
        $revision->setObjectId($entity->getId());
        $revision->setClass(get_class($entity));
        $revision->setJsonObject($this->serializeEntity($entity));
        $revision->setDate(new \DateTime());
        $this->em->persist($revision);
        $this->em->flush();
    }

    public function compareEntityVersion($entity, $date = null, $lastVersionNumber = null) {
        //si date = null, on prend la dernière sinon la dernière version à cette date
        //si versionNumber != null, on prend la x ème version en partant de l'actuelle
    }

    /**
     * serialize an entity in json string
     * @param $entity
     * @return string
     * @throws \Exception
     */
    public function serializeEntity($entity) {
        $tab = array();

        //if extends another class
        if(strstr(get_class($entity), "Proxies")) {
            $className = ClassUtils::getClass($entity);
        } else {
            $className = get_class($entity);
        }
        //get name of class in lowercase
        $name = strtolower(substr($className, strrpos($className, '\\') + 1));

        //get reflectionClass for use get method
        $reflectionClass = new \ReflectionClass($className);
        $properties = $this->getClassProperties($className);
        foreach ($properties as $refProperty) {
            $propName = $refProperty->getName();
            if ((empty($this->configs[$name]['fields']) || in_array($propName, $this->configs[$name]['fields'])) && !in_array($propName, $this->configs[$name]['ignore_fields']) && $this->reader->getPropertyAnnotation($refProperty, Id::class) == null) {
                //if relation entity
                $annotation = $this->getAnnotation($refProperty);

                //if is public access
                if ($refProperty->isPublic()) {
                    if ($annotation == null) {
                        $tab[$propName] = $entity->$propName;
                    } else {
                        //relations
                        if (array_key_exists($this::ENTITY_PROPERTY_ONE, $annotation)) {
                            $tab[$propName] = json_decode($this->serializeEntity($entity->$propName), true);
                        } else {
                            $tab[$propName] = array();
                            foreach ($entity->$propName as $subEntity) {
                                $tab[$propName][] = json_decode($this->serializeEntity($subEntity), true);
                            }
                        }
                    }
                } else {
                    //if is private or protected access
                    $tabName = explode('_', $propName);
                    $methodName = '';
                    foreach ($tabName as $tabNameExplode) {
                        $methodName .= ucfirst($tabNameExplode);
                    }

                    if ($reflectionClass->hasMethod($getter = 'get' . $methodName)) {
                        try {
                            if ($annotation == null) {
                                $tab[$propName] = $entity->$getter();
                            } else {
                                //relations
                                if (array_key_exists($this::ENTITY_PROPERTY_ONE, $annotation)) {
                                    $tab[$propName] = json_decode($this->serializeEntity($entity->$getter()), true);
                                } else {
                                    $tab[$propName] = array();
                                    foreach ($entity->$getter() as $subEntity) {
                                        $tab[$propName][] = json_decode($this->serializeEntity($subEntity), true);
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            //if not have get method
                            throw new \ErrorException($e->getMessage(), $e->getCode(), 1, $e->getFile(), $e->getLine());
                        }
                    } else {
                        //if not have get method
                        throw new \ErrorException('Impossible to execute ' . $getter . ' method in ' . $className);
                    }
                }
            }
        }

        return json_encode($tab);
    }

    /**
     * return an entity
     * @param string $className
     * @param string $json
     * @return mixed
     * @throws \Exception
     */
    public function unserializeEntity($className, $json) {
        $tab = json_decode($json, true);
        $reflectionClass = new \ReflectionClass($className);
        //new entity
        $entity = new $className();
        //get name of class in lowercase
        $name = strtolower(substr($className, strrpos($className, '\\') + 1));

        $properties = $this->getClassProperties($className);
        foreach ($properties as $refProperty) {
            $propName = $refProperty->getName();
            if ((empty($this->configs[$name]['fields']) || in_array($propName, $this->configs[$name]['fields'])) && !in_array($propName, $this->configs[$name]['ignore_fields']) && $this->reader->getPropertyAnnotation($refProperty, Id::class) == null) {
                //if relation entity
                $annotation = $this->getAnnotation($refProperty);

                //if is public access
                if ($refProperty->isPublic()) {
                    if ($annotation == null) {
                        $entity->$propName = $tab[$propName];
                    } else {
                        //relations
                        if (array_key_exists($this::ENTITY_PROPERTY_ONE, $annotation)) {
                            $entity->$propName = $this->unserializeEntity($annotation[$this::ENTITY_PROPERTY_ONE], json_encode($tab[$propName]));
                        } else {
                            $colection = new ArrayCollection();
                            foreach ($tab[$propName] as $subValue) {
                                $colection->add($this->unserializeEntity($annotation[$this::ENTITY_PROPERTY_MANY], json_encode($subValue)));
                            }
                            $entity->$propName = $colection;
                        }
                    }
                } else {
                    //if is private or protected access
                    $tabName = explode('_', $propName);
                    $methodName = '';
                    foreach ($tabName as $tabNameExplode) {
                        $methodName .= ucfirst($tabNameExplode);
                    }
                    if ($reflectionClass->hasMethod($setter = 'set' . $methodName)) {
                        try {
                            if ($annotation == null) {
                                $entity->$setter($tab[$propName]);
                            } else {
                                //relations
                                if (array_key_exists($this::ENTITY_PROPERTY_ONE, $annotation)) {
                                    $entity->$setter($this->unserializeEntity($annotation[$this::ENTITY_PROPERTY_ONE], json_encode($tab[$propName])));
                                } else {
                                    $colection = new ArrayCollection();
                                    foreach ($tab[$propName] as $subValue) {
                                        $colection->add($this->unserializeEntity($annotation[$this::ENTITY_PROPERTY_MANY], json_encode($subValue)));
                                    }
                                    $entity->$setter($colection);
                                }
                            }
                        } catch (\Exception $e) {
                            //if not have get method
                            throw new \ErrorException($e->getMessage(), $e->getCode(), 1, $e->getFile(), $e->getLine());
                        }
                    } else {
                        //if not have get method
                        throw new \ErrorException('Impossible to execute ' . $setter . ' method in ' . $className);
                    }
                }
            }
        }

        return $entity;
    }

    /**
     * Recursive function to get an associative array of class properties
     * including inherited ones from extended classes
     * @param string $className
     * @return array
     */
    protected function getClassProperties($className){
        $reflectionClass = new \ReflectionClass($className);
        $properties = $reflectionClass->getProperties();
        if($parentClass = $reflectionClass->getParentClass()){
            $parentPropertiesArray = $this->getClassProperties($parentClass->getName());
            if(count($parentPropertiesArray) > 0) {
                $properties = array_merge($parentPropertiesArray, $properties);
            }
        }
        return $properties;
    }

    /**
     * get type of relation
     * @param $refProperty
     * @return array|null
     */
    protected function getAnnotation($refProperty) {
        if ($this->reader->getPropertyAnnotation($refProperty, ManyToOne::class)) {
            return array($this::ENTITY_PROPERTY_ONE => $this->reader->getPropertyAnnotation($refProperty, ManyToOne::class)->targetEntity);
        }
        if ($this->reader->getPropertyAnnotation($refProperty, OneToOne::class)) {
            return array($this::ENTITY_PROPERTY_ONE => $this->reader->getPropertyAnnotation($refProperty, OneToOne::class)->targetEntity);
        }
        if ($this->reader->getPropertyAnnotation($refProperty, Embedded::class)) {
            return array($this::ENTITY_PROPERTY_ONE => $this->reader->getPropertyAnnotation($refProperty, Embedded::class)->class);
        }
        if ($this->reader->getPropertyAnnotation($refProperty, OneToMany::class)) {
            return array($this::ENTITY_PROPERTY_MANY => $this->reader->getPropertyAnnotation($refProperty, OneToMany::class)->targetEntity);
        }
        if ($this->reader->getPropertyAnnotation($refProperty, ManyToMany::class)) {
            return array($this::ENTITY_PROPERTY_MANY => $this->reader->getPropertyAnnotation($refProperty, ManyToMany::class)->targetEntity);
        }
        return null;
    }

}