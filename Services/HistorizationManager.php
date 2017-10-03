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
        if ($tokenStorage->getToken() != null && !$authorizationChecker->isGranted('IS_AUTHENTICATED_ANONYMOUSLY')) {
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
     * @return array
     */
    public function historizationEntity($entity, EntityManager $em = null)
    {
        return $this->historizationEntity(array($entity), $em);
    }

    /**
     * register the actual version of entities
     * or origin entities if have a propertyOrigin specify
     * @param array $entities
     * @param EntityManager $em
     * @return array
     */
    public function historizationEntities($entities, EntityManager $em = null) {
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
        $entities = $this->historizableEntities($entities);
        $revs = array();

        foreach ($entities as $entity) {
            $revision = new $classAudit();
            $revision->setUserProperty($user);
            $revision->setObjectId($entity->getId());
            $revision->setClass(get_class($entity));
            $revision->setJsonObject($this->serializeEntity($entity));
            $revision->setDate(new \DateTime());
            //if entityManager is specify, persist automaticaly
            if ($em != null) {
                $em->persist($revision);
            }
        }

        return $revs;
    }

    /**
     * @param $repository
     * @param $objectId
     * @param null $id
     * @return null|object
     */
    public function getVersion($repository, $objectId, $id = null) {
        if ($id == null) {
            $revision = $repository->findOneBy(array('object_id' => $objectId), array('id' => 'DESC'));
        } else {
            $revision = $repository->find($id);
        }

        return $revision;
    }

    /**
     * return an array with differences and null if there isn't history for entity
     * array result (
     *     classic property : propName => [entity version, history version]
     *     embeded, OneToOne or ManyToOne : propName => ['entity' => the same array that this]
     *     OneToMany or ManyToMany : propName => ['collection' => [first entity id => the same array that this]...[last entity id => the same array that this], ['delete' => array of entities ids were remove], ['add' => array of entities ids were add] ]
     * )
     * @param $repository
     * @param $entity
     * @param $revision
     * @param $id the id of revision you want to use for compare
     * @return array|null
     */
    public function compareEntityVersion($repository, $entity, $revision = null, $id = null) {
        if (!$revision) {
            $revision = $this->getVersion($repository, $entity->getId(), $id);
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
            $reflectionClass = new \ReflectionClass($entity);
            if(strstr(get_class($entity), "Proxies")) {
                $className = ClassUtils::getClass($entity);
            } else {
                $className = get_class($entity);
            }
            $annotation = $this->reader->getClassAnnotation($reflectionClass, HistoryAnnotation::class);
            if ($annotation) {
                if ($annotation->propertyOrigin && $annotation->getOrigin()) {
                    $tabName = explode('_', $annotation->propertyOrigin);
                    $methodName = '';
                    foreach ($tabName as $tabNameExplode) {
                        $methodName .= ucfirst($tabNameExplode);
                    }
                    if ($reflectionClass->hasMethod($getter = 'get' . $methodName)) {
                        $get = $entity->$getter();
                        if (is_array($get)) {
                            foreach ($get as $res) {
                                $tab = $this->hasHistorizableEntities($res);
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
                            $tab = $this->hasHistorizableEntities($get);
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
            if ((empty($this->configs[$name]['fields']) || in_array($propName, $this->configs[$name]['fields'])) && !in_array($propName, $this->configs[$name]['ignore_fields']) && $this->reader->getPropertyAnnotation($refProperty, Id::class) == null) {
                //if relation entity
                $annotation = $this->getAnnotation($refProperty);

                //if is public access
                if ($refProperty->isPublic()) {
                    if ($annotation == null) {
                        if ($entityHistory->$propName != $entity->$propName) {
                            $tabCompare[$propName] = array($entity->$propName, $entityHistory->$propName);
                        }
                    } else {
                        //relations
                        if (array_key_exists($this::ENTITY_PROPERTY_ONE, $annotation)) {
                            $result = $this->compare($entity->$propName, $entityHistory->$propName, $annotation[$this::ENTITY_PROPERTY_ONE]);
                            if (!empty($result)) {
                                $tabCompare[$propName]['entity'] = $result;
                            }
                        } else {
                            $deleteEntity = array();
                            $addEntity = array();
                            $first = true;
                            foreach ($entity->$propName as $subEntity) {
                                $subEntityExist = false;
                                foreach ($entityHistory->$propName as $subEntityHistory) {
                                    if ($first) {
                                        $deleteEntity[] = $subEntityHistory->getId();
                                    }
                                    if ($subEntity->getId() == $subEntityHistory->getId()) {
                                        $subEntityExist = true;
                                        unset($deleteEntity[array_search($subEntityHistory->getId(), $deleteEntity)]);
                                        $result = $this->compare($subEntity, $subEntityHistory, $annotation[$this::ENTITY_PROPERTY_MANY]);
                                        if (!empty($result)) {
                                            $tabCompare[$propName]['collection'][$subEntity->getId()] = $result;
                                        }
                                        if (!$first) {
                                            break;
                                        }
                                    }
                                }
                                $first = false;
                                if (!$subEntityExist) {
                                    $addEntity[] = $subEntity->getId();
                                }
                            }
                            $tabCompare[$propName]['collection']['delete'] = $deleteEntity;
                            $tabCompare[$propName]['collection']['delete'] = $addEntity;
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
                                if ($entityHistory->$getter() != $entity->$getter()) {
                                    $tabCompare[$propName] = array($entity->$getter(), $entityHistory->$getter());
                                }
                            } else {
                                //relations
                                if (array_key_exists($this::ENTITY_PROPERTY_ONE, $annotation)) {
                                    $result = $this->compare($entity->$getter(), $entityHistory->$getter(), $annotation[$this::ENTITY_PROPERTY_ONE]);
                                    if (!empty($result)) {
                                        $tabCompare[$propName]['entity'] = $result;
                                    }
                                } else {
                                    $deleteEntity = array();
                                    $addEntity = array();
                                    $first = true;
                                    foreach ($entity->$getter() as $subEntity) {
                                        $subEntityExist = false;
                                        foreach ($entityHistory->$getter() as $subEntityHistory) {
                                            if ($first) {
                                                $deleteEntity[] = $subEntityHistory->getId();
                                            }
                                            if ($subEntity->getId() == $subEntityHistory->getId()) {
                                                $subEntityExist = true;
                                                unset($deleteEntity[array_search($subEntityHistory->getId(), $deleteEntity)]);
                                                $result = $this->compare($subEntity, $subEntityHistory, $annotation[$this::ENTITY_PROPERTY_MANY]);
                                                if (!empty($result)) {
                                                    $tabCompare[$propName]['collection'][$subEntity->getId()] = $result;
                                                }
                                                if (!$first) {
                                                    break;
                                                }
                                            }
                                        }
                                        $first = false;
                                        if (!$subEntityExist) {
                                            $addEntity[] = $subEntity->getId();
                                        }
                                    }
                                    $tabCompare[$propName]['collection']['delete'] = $deleteEntity;
                                    $tabCompare[$propName]['collection']['delete'] = $addEntity;
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