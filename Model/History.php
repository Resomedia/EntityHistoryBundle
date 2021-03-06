<?php

namespace Resomedia\EntityHistoryBundle\Model;

use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping as ORM;

/**
 * Class History
 * @package Resomedia\EntityHistoryBundle\Model
 * @MappedSuperclass
 */
abstract class History
{
    const ANONYMOUS = 'anonymous';
    const STATE_INSERT = 0;
    const STATE_UPDATE = 1;

    /**
     * @var string $user_property
     * @ORM\Column(name="user_property", type="string", length=255, nullable=false)
     */
    protected $user_property;

    /**
     * @var string $object_id
     * @ORM\Column(name="object_id", type="string", length=255, nullable=true)
     */
    protected $object_id;

    /**
     * @var string $state
     * @ORM\Column(name="state", type="integer", nullable=false)
     */
    protected $state;

    /**
     * @var string $class
     * @ORM\Column(name="class", type="string", length=255, nullable=false)
     */
    protected $class;

    /**
     * @var text $json_object
     * @ORM\Column(name="json_object", type="text", nullable=false)
     */
    protected $json_object;

    /**
     * @var \DateTime
     * @ORM\Column(name="date", type="datetime")
     */
    protected $date;

    /**
     * @return text
     */
    public function getJsonObject() {
        return $this->json_object;
    }

    /**
     * @param $jsonObject
     */
    public function setJsonObject($jsonObject) {
        $this->json_object = $jsonObject;
    }

    /**
     * @return string
     */
    public function getUserProperty() {
        return $this->user_property;
    }

    /**
     * @param $userProperty
     */
    public function setUserProperty($userProperty) {
        $this->user_property = $userProperty;
    }

    /**
     * @return string
     */
    public function getObjectId() {
        return $this->object_id;
    }

    /**
     * @param $objectId
     */
    public function setObjectId($objectId) {
        $this->object_id = $objectId;
    }

    /**
     * @return string
     */
    public function getClass() {
        return $this->class;
    }

    /**
     * @param $class
     */
    public function setClass($class) {
        $this->class = $class;
    }

    /**
     * @return \DateTime
     */
    public function getDate() {
        return $this->date;
    }

    /**
     * @param $date
     */
    public function setDate($date) {
        $this->date = $date;
    }

    /**
     * @return integer
     */
    public function getState() {
        return $this->state;
    }

    /**
     * @param $state
     */
    public function setState($state) {
        $this->state = $state;
    }

    /**
     * Call after the create history object process
     * You can override this for add additionnal process and modify revision before persist
     * In param, the entity was modify
     * @param mixed $origineEntity
     * @param mixed $historizableEntity
     * @return mixed
     */
    public function addProcess($origineEntity, $historizableEntity) {
        return null;
    }
}