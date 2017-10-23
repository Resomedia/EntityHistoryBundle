<?php

namespace Resomedia\EntityHistoryBundle\Services;

use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Embedded;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Resomedia\EntityHistoryBundle\Model\History;
use Resomedia\EntityHistoryBundle\Annotation\History as HistoryAnnotation;
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
     * @param $userProperty
     * @param $classAudit
     * @param $authorizationChecker
     * @param $tokenStorage
     * @param $EntityConfigs
     * @param Reader $annReader
     */
    public function __construct($userProperty, $classAudit, AuthorizationChecker $authorizationChecker, TokenStorage $tokenStorage, $EntityConfigs, Reader $annReader)
    {
        $this->reader = $annReader;
        $this->class_audit = $classAudit;
        $this->user_property = $userProperty;
        if ($tokenStorage->getToken() != null && $authorizationChecker->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            $this->current_user = $tokenStorage->getToken()->getUser();
        } else {
            $this->current_user = History::ANONYMOUS;
        }

        $this->configs = $EntityConfigs;
    }

    /**
     * alias of historizationEntities
     * @param mixed $entity
     * @param EntityManager $em
     * @param integer $state
     * @return array
     */
    public function historizationEntity($entity, EntityManager $em = null, $state = null)
    {
        return $this->historizationEntities(array($entity), $em, $state);
    }

    /**
     * register the actual version of entities
     * or origin entities if have a propertyOrigin specify
     * @param array $entitiesO
     * @param EntityManager $em
     * @param integer $state
     * @return array
     */
    public function historizationEntities($entitiesO, EntityManager $em = null, $state = null) {
        $classAudit = $this->class_audit;
        if ($this->current_user != History::ANONYMOUS) {
            $tabName = explode('_', $this->user_property);
            $methodName = '';
            foreach ($tabName as $tabNameExplode) {
                $methodName .= ucfirst($tabNameExplode);
            }
            $methodName = 'get' . $methodName;
            $user = $this->current_user->$methodName();

        } else {
            $user = $this->current_user;
        }

        $entities = array();
        foreach ($entitiesO as $entity) {
            $entities[] = array($entity, $this->historizableEntities(array($entity)));
        }

        $revs = array();
        foreach ($entities as $cycleEntity) {
            foreach ($cycleEntity[1] as $entity) {
                //if you want, you can force state & create your own state
                if ($state === null) {
                    if ($entity->getId() !== null) {
                        $state = History::STATE_UPDATE;
                    } else {
                        $state = History::STATE_INSERT;
                    }
                }
                $revision = new $classAudit();
                $revision->setState($state);
                $revision->setUserProperty($user);
                $revision->setObjectId($entity->getId());
                if(strstr(get_class($entity), "Proxies")) {
                    $className = ClassUtils::getClass($entity);
                } else {
                    $className = get_class($entity);
                }
                $revision->setClass($className);
                $revision->setJsonObject($this->serializeEntity($entity));
                $revision->setDate(new \DateTime());
                $revision->addProcess($cycleEntity[0], $entity);
                //if entityManager is specify, persist automaticaly
                if ($em != null) {
                    $em->persist($revision);
                }
                $revs[] = $revision;
            }
        }

        return $revs;
    }

    /**
     * @param $repository
     * @param $objectId
     * @param null $className
     * @param null $id
     * @return mixed
     * @throws \Exception
     */
    public function getVersion($repository, $objectId, $className = null, $id = null) {
        if ($className == null && $id == null) {
            throw new \Exception('classname or id should be defined', 500);
        }
        if ($id == null) {
            $revision = $repository->findOneBy(array('object_id' => $objectId, 'class' => $className), array('id' => 'DESC'));
        } else {
            $revision = $repository->find($id);
        }

        return $revision;
    }

    /**
     * return an array with differences and null if there isn't history for entity
     * array result (
     *     classic property : propName => [entity version, history version]
     *     embeded, OneToOne or ManyToOne : propName => ['entity' => the same array that this, 'delete' => bool, 'add' => bool]
     *     OneToMany or ManyToMany : propName => ['collection' => [first entity id => the same array that this, ..., last entity id => the same array that this], 'delete' => array of entities ids were remove, 'add' => array of entities ids were add ]
     * )
     * @param $repository
     * @param $entity
     * @param $revision
     * @param $id the id of revision you want to use for compare
     * @return array|null
     */
    public function compareEntityVersion($repository, $entity, $revision = null, $id = null) {
        if (!$revision) {
            if(strstr(get_class($entity), "Proxies")) {
                $className = ClassUtils::getClass($entity);
            } else {
                $className = get_class($entity);
            }
            $revision = $this->getVersion($repository, $entity->getId(), $className, $id);
            if (!$revision) {
                return null;
            }
        }
        $entityHistory = $this->unserializeEntity($revision->getClass(), $revision->getJsonObject());

        return $this->compare($entity, $entityHistory, $revision->getClass());
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
            if ((empty($this->configs[$name]['fields']) || in_array($propName, $this->configs[$name]['fields'])) && !in_array($propName, $this->configs[$name]['ignore_fields'])) {
                //if relation entity
                $annotation = $this->getAnnotation($refProperty);

                //if is public access
                if ($refProperty->isPublic()) {
                    if ($annotation == null) {
                        $tab[$propName] = $entity->$propName;
                    } else {
                        //relations
                        if ($entity->$propName != null) {
                            if ($annotation[0] == $this::ENTITY_PROPERTY_ONE) {
                                $tab[$propName] = json_decode($this->serializeEntity($entity->$propName), true);
                            } else {
                                $tab[$propName] = array();
                                foreach ($entity->$propName as $subEntity) {
                                    $tab[$propName][] = json_decode($this->serializeEntity($subEntity), true);
                                }
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
                                if ($entity->$getter() != null) {
                                    //relations
                                    if ($annotation[0] == $this::ENTITY_PROPERTY_ONE) {
                                        $tab[$propName] = json_decode($this->serializeEntity($entity->$getter()), true);
                                    } else {
                                        $tab[$propName] = array();
                                        foreach ($entity->$getter() as $subEntity) {
                                            $tab[$propName][] = json_decode($this->serializeEntity($subEntity), true);
                                        }
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
            if ((empty($this->configs[$name]['fields']) || in_array($propName, $this->configs[$name]['fields'])) && !in_array($propName, $this->configs[$name]['ignore_fields']) && array_key_exists($propName, $tab)) {
                //if relation entity
                $annotation = $this->getAnnotation($refProperty);

                //if is public access
                if ($refProperty->isPublic()) {
                    if ($annotation == null) {
                        $entity->$propName = $tab[$propName];
                    } else {
                        //relations
                        if ($annotation[0] == $this::ENTITY_PROPERTY_ONE) {
                            $entity->$propName = $this->unserializeEntity($annotation[1], json_encode($tab[$propName]));
                        } else {
                            $colection = new ArrayCollection();
                            foreach ($tab[$propName] as $subValue) {
                                $colection->add($this->unserializeEntity($annotation[1], json_encode($subValue)));
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
                    $setter = 'set' . $methodName;
                    $adder = 'add' . $methodName;
                    $adderss = 'add' . substr($methodName, 0, strlen($methodName) - 1);
                    if ($reflectionClass->hasMethod($setter) || $reflectionClass->hasMethod($adder) || $reflectionClass->hasMethod($adderss)) {
                        try {
                            if ($annotation == null) {
                                $entity->$setter($tab[$propName]);
                            } else {
                                //relations
                                if ($annotation[0] == $this::ENTITY_PROPERTY_ONE) {
                                    $entity->$setter($this->unserializeEntity($annotation[1], json_encode($tab[$propName])));
                                } else {
                                    if ($reflectionClass->hasMethod($adder) || $reflectionClass->hasMethod($adderss)) {
                                        foreach ($tab[$propName] as $subValue) {
                                            if ($reflectionClass->hasMethod($adder)) {
                                                $entity->$adder($this->unserializeEntity($annotation[1], json_encode($subValue)));
                                            } else {
                                                $entity->$adderss($this->unserializeEntity($annotation[1], json_encode($subValue)));
                                            }
                                        }
                                    } else {
                                        $colection = new ArrayCollection();
                                        foreach ($tab[$propName] as $subValue) {
                                            $colection->add($this->unserializeEntity($annotation[1], json_encode($subValue)));
                                        }
                                        $entity->$setter($colection);
                                    }
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
     * @param string|object $className
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
     * get entity historizable define by propertyOrigin
     * one entity can be referenced only one
     * @param array $entities
     * @return null
     * @throws \Exception
     */
    protected function historizableEntities($entities) {
        $tabEntities = array();
        $tabCompare = array();
        foreach ($entities as $entity) {
            if(strstr(get_class($entity), "Proxies")) {
                $className = ClassUtils::getClass($entity);
            } else {
                $className = get_class($entity);
            }
            $reflectionClass = new \ReflectionClass($className);
            $annotation = $this->reader->getClassAnnotation($reflectionClass, HistoryAnnotation::class);
            if ($annotation) {
                if ($annotation->propertyOrigin) {
                    $tabName = explode('_', $annotation->propertyOrigin);
                    $methodName = '';
                    foreach ($tabName as $tabNameExplode) {
                        $methodName .= ucfirst($tabNameExplode);
                    }
                    if ($reflectionClass->hasMethod($getter = 'get' . $methodName)) {
                        $get = $entity->$getter();
                        if ($annotation->getOrigin($get)) {
                            if (is_array($get)) {
                                foreach ($get as $res) {
                                    $tab = $this->historizableEntities($res);
                                    foreach ($tab as $ent) {
                                        if (array_key_exists($className, $tabCompare)) {
                                            if (!in_array($ent->getId(), $tabCompare[$className])) {
                                                $tabCompare[$className][] = $ent->getId();
                                                $tabEntities[] = $ent;
                                            }
                                        } else {
                                            $tabCompare[$className] = array($ent->getId());
                                            $tabEntities[] = $ent;
                                        }
                                    }
                                }
                            } else {
                                $tab = $this->historizableEntities(array($get));
                                foreach ($tab as $ent) {
                                    if (array_key_exists($className, $tabCompare)) {
                                        if (!in_array($ent->getId(), $tabCompare[$className])) {
                                            $tabCompare[$className][] = $ent->getId();
                                            $tabEntities[] = $ent;
                                        }
                                    } else {
                                        $tabCompare[$className] = array($ent->getId());
                                        $tabEntities[] = $ent;
                                    }
                                }
                            }
                        } else {
                            if (array_key_exists($className, $tabCompare)) {
                                if (!in_array($entity->getId(), $tabCompare[$className])) {
                                    $tabCompare[$className][] = $entity->getId();
                                    $tabEntities[] = $entity;
                                }
                            } else {
                                $tabCompare[$className] = array($entity->getId());
                                $tabEntities[] = $entity;
                            }
                        }
                    } else {
                        throw new \Exception('No method ' . $getter . ' exist', 500);
                    }
                } else {
                    if (array_key_exists($className, $tabCompare)) {
                        if (!in_array($entity->getId(), $tabCompare[$className])) {
                            $tabCompare[$className][] = $entity->getId();
                            $tabEntities[] = $entity;
                        }
                    } else {
                        $tabCompare[$className] = array($entity->getId());
                        $tabEntities[] = $entity;
                    }
                }
            }
        }

        return $tabEntities;
    }

    /**
     * Difference between two instance of an entity at differente instant
     * @param $entity
     * @param $entityHistory
     * @param $class
     * @return array
     * @throws \ErrorException
     */
    protected function compare($entity, $entityHistory, $class) {
        $tabCompare = array();
        $reflectionClass = new \ReflectionClass($class);

        //get name of class in lowercase
        $name = strtolower(substr($class, strrpos($class, '\\') + 1));

        $properties = $this->getClassProperties($class);
        foreach ($properties as $refProperty) {
            $propName = $refProperty->getName();
            if ((empty($this->configs[$name]['fields']) || in_array($propName, $this->configs[$name]['fields'])) && !in_array($propName, $this->configs[$name]['ignore_fields'])) {
                //if relation entity
                $annotation = $this->getAnnotation($refProperty);

                //if is public access
                if ($refProperty->isPublic()) {
                    if ($annotation == null) {
                        if ($entity->$propName instanceof \DateTime) {
                            if (is_array($entityHistory->$propName) && array_key_exists('date', $entityHistory->$propName)) {
                                $dateHistory = new \DateTime($entityHistory->$propName['date']);
                            } else {
                                $dateHistory = new \DateTime($entityHistory->$propName);
                            }
                            if ($dateHistory->format('d/m/Y H:i') != $entity->$propName->format('d/m/Y H:i')) {
                                $tabCompare[$propName] = array($entity->$propName->format('d/m/Y H:i'), $dateHistory->format('d/m/Y H:i'));
                            }
                        } else {
                            if ($entityHistory->$propName != $entity->$propName) {
                                $tabCompare[$propName] = array($entity->$propName, $entityHistory->$propName);
                            }
                        }
                    } else {
                        //relations
                        if ($annotation[0] == $this::ENTITY_PROPERTY_ONE) {
                            if ($entity->$propName === null && $entityHistory->$propName !== null) {
                                $tabCompare[$propName]['entity'] = null;
                                $tabCompare[$propName]['delete'] = true;
                                $tabCompare[$propName]['add'] = false;
                            } elseif ($entityHistory->$propName === null && $entity->$propName !== null) {
                                $tabCompare[$propName]['entity'] = null;
                                $tabCompare[$propName]['delete'] = false;
                                $tabCompare[$propName]['add'] = true;
                            } elseif ($entity->$propName !== null && $entityHistory->$propName !== null) {
                                $result = $this->compare($entity->$propName, $entityHistory->$propName, $annotation[1]);
                                if ($result && count($result) > 0) {
                                    $tabCompare[$propName]['entity'] = $result;
                                    $tabCompare[$propName]['delete'] = false;
                                    $tabCompare[$propName]['add'] = false;
                                }
                            }
                        } else {
                            $deleteEntity = array();
                            $notDeleteEntity = array();
                            $addEntity = array();
                            $collection = array();
                            $first = true;
                            foreach ($entity->$propName as $subEntity) {
                                $add = true;
                                foreach ($entityHistory->$propName as $subEntityHistory) {
                                    if ($first) {
                                        $deleteEntity[] = $subEntityHistory->getId();
                                    }
                                    if ($subEntity->getId() == $subEntityHistory->getId()) {
                                        $add = false;
                                        $notDeleteEntity[] = $subEntityHistory->getId();
                                        $result = $this->compare($subEntity, $subEntityHistory, $annotation[1]);
                                        if ($result && count($result) > 0) {
                                            $collection[$subEntity->getId()] = $result;
                                        }
                                        if (!$first) {
                                            break;
                                        }
                                    }
                                }
                                $first = false;
                                if ($add) {
                                    $addEntity[] = $subEntity->getId();
                                }
                            }
                            $deleteEntity = array_diff($deleteEntity, $notDeleteEntity);
                            if (count($deleteEntity) > 0 || count($addEntity) > 0 || count($collection) > 0) {
                                $tabCompare[$propName]['collection'] = $collection;
                                $tabCompare[$propName]['delete'] = $deleteEntity;
                                $tabCompare[$propName]['add'] = $addEntity;
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
                                if ($entity->$getter() instanceof \DateTime) {
                                    if (is_array($entityHistory->$getter()) && array_key_exists('date', $entityHistory->$getter())) {
                                        $dateHistory = new \DateTime($entityHistory->$getter()['date']);
                                    } else {
                                        $dateHistory = new \DateTime($entityHistory->$getter());
                                    }
                                    if ($dateHistory->format('d/m/Y H:i') != $entity->$getter()->format('d/m/Y H:i')) {
                                        $tabCompare[$propName] = array($entity->$getter()->format('d/m/Y H:i'), $dateHistory->format('d/m/Y H:i'));
                                    }
                                } else {
                                    if ($entityHistory->$getter() != $entity->$getter()) {
                                        $tabCompare[$propName] = array($entity->$getter(), $entityHistory->$getter());
                                    }
                                }
                            } else {
                                //relations
                                if ($annotation[0] == $this::ENTITY_PROPERTY_ONE) {
                                    if ($entity->$getter() === null && $entityHistory->$getter() !== null) {
                                        $tabCompare[$propName]['entity'] = null;
                                        $tabCompare[$propName]['delete'] = true;
                                        $tabCompare[$propName]['add'] = false;
                                    } elseif ($entityHistory->$getter() === null && $entity->$getter() !== null) {
                                        $tabCompare[$propName]['entity'] = null;
                                        $tabCompare[$propName]['delete'] = false;
                                        $tabCompare[$propName]['add'] = true;
                                    } elseif ($entity->$getter() !== null && $entityHistory->$getter() !== null) {
                                        $result = $this->compare($entity->$getter(), $entityHistory->$getter(), $annotation[1]);
                                        if ($result && count($result) > 0) {
                                            $tabCompare[$propName]['entity'] = $result;
                                            $tabCompare[$propName]['delete'] = false;
                                            $tabCompare[$propName]['add'] = false;
                                        }
                                    }
                                } else {
                                    $deleteEntity = array();
                                    $notDeleteEntity = array();
                                    $addEntity = array();
                                    $collection = array();
                                    $first = true;
                                    foreach ($entity->$getter() as $subEntity) {
                                        $add = true;
                                        foreach ($entityHistory->$getter() as $subEntityHistory) {
                                            if ($first) {
                                                $deleteEntity[] = $subEntityHistory->getId();
                                            }
                                            if ($subEntity->getId() == $subEntityHistory->getId()) {
                                                $add = false;
                                                $notDeleteEntity[] = $subEntityHistory->getId();
                                                $result = $this->compare($subEntity, $subEntityHistory, $annotation[1]);
                                                if ($result && count($result) > 0) {
                                                    $collection[$subEntity->getId()] = $result;
                                                }
                                                if (!$first) {
                                                    break;
                                                }
                                            }
                                        }
                                        $first = false;
                                        if ($add) {
                                            $addEntity[] = $subEntity->getId();
                                        }
                                    }
                                    $deleteEntity = array_diff($deleteEntity, $notDeleteEntity);
                                    if (count($deleteEntity) > 0 || count($addEntity) > 0 || count($collection) > 0) {
                                        $tabCompare[$propName]['collection'] = $collection;
                                        $tabCompare[$propName]['delete'] = $deleteEntity;
                                        $tabCompare[$propName]['add'] = $addEntity;
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            //if not have get method
                            throw new \ErrorException($e->getMessage(), $e->getCode(), 1, $e->getFile(), $e->getLine());
                        }
                    } else {
                        //if not have get method
                        throw new \ErrorException('Impossible to execute ' . $getter . ' method in ' . $class);
                    }
                }
            }
        }

        return $tabCompare;
    }

    /**
     * get type of relation
     * @param $refProperty
     * @return array|null
     */
    protected function getAnnotation($refProperty) {
        $res = array();
        $find = false;
        if ($this->reader->getPropertyAnnotation($refProperty, ManyToOne::class)) {
            $find = true;
            $res[0] = $this::ENTITY_PROPERTY_ONE;
            $res[1] = $this->reader->getPropertyAnnotation($refProperty, ManyToOne::class)->targetEntity;
        }
        if ($this->reader->getPropertyAnnotation($refProperty, OneToOne::class)) {
            $find = true;
            $res[0] = $this::ENTITY_PROPERTY_ONE;
            $res[1] = $this->reader->getPropertyAnnotation($refProperty, OneToOne::class)->targetEntity;
        }
        if ($this->reader->getPropertyAnnotation($refProperty, Embedded::class)) {
            $find = true;
            $res[0] = $this::ENTITY_PROPERTY_ONE;
            $res[1] = $this->reader->getPropertyAnnotation($refProperty, Embedded::class)->class;
        }
        if ($this->reader->getPropertyAnnotation($refProperty, OneToMany::class)) {
            $find = true;
            $res[0] = $this::ENTITY_PROPERTY_MANY;
            $res[1] = $this->reader->getPropertyAnnotation($refProperty, OneToMany::class)->targetEntity;
        }
        if ($this->reader->getPropertyAnnotation($refProperty, ManyToMany::class)) {
            $find = true;
            $res[0] = $this::ENTITY_PROPERTY_MANY;
            $res[1] = $this->reader->getPropertyAnnotation($refProperty, ManyToMany::class)->targetEntity;
        }
        if (!$find) {
            return null;
        }
        if (strpos($res[1], '\\') === false) {
            $path = substr($refProperty->class, 0, strrpos($refProperty->class, '\\') + 1);
            $res[1] = $path . $res[1];
        }
        return $res;
    }

}