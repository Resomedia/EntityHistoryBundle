<?php

namespace Resomedia\EntityHistoryBundle\Annotation;
use Doctrine\Common\Annotations\Annotation\Target;

/**
 * The History class handles the @History annotation.
 * @Annotation
 * @Target("CLASS")
 */
class History {

    const OPERATION_EQUALS = 0;
    const OPERATION_COUNTMORE = 1;
    const OPERATION_COUNTLESS = 2;
    const OPERATION_COUNTMOREEQUALS = 3;
    const OPERATION_COUNTLESSEQUALS = 4;
    const OPERATION_NOTEQUALS = 5;
    const OPERATION_STRICTNOTEQUALS = 6;
    const OPERATION_STRICTEQUALS = 7;

    /**
     * Parameter propertyOrigin
     * @var string
     */
    public $propertyOrigin;

    /**
     * Parameter conditionValue
     * @var mixed
     */
    public $conditionValue;

    /**
     * Parameter operation
     * @var integer
     */
    public $operation;

    /**
     * @param mixed $value
     * @return boolean
     */
    public function getOrigin($value)
    {
        if ($this->conditionValue === null) {
            return true;
        }
        if ($this->conditionValue == 'NULL') {
            $this->conditionValue = null;
        }
        if ($this->operation === null) {
            $this->operation = $this::OPERATION_EQUALS;
        }
        switch ($this->operation) {
            case $this::OPERATION_EQUALS:
                if ($value == $this->conditionValue) {
                    return true;
                }
                break;
            case $this::OPERATION_COUNTMORE:
                if ($value > $this->conditionValue) {
                    return true;
                }
                break;
            case $this::OPERATION_COUNTLESS:
                if ($value < $this->conditionValue) {
                    return true;
                }
                break;
            case $this::OPERATION_COUNTMOREEQUALS:
                if ($value >= $this->conditionValue) {
                    return true;
                }
                break;
            case $this::OPERATION_COUNTLESSEQUALS:
                if ($value <= $this->conditionValue) {
                    return true;
                }
                break;
            case $this::OPERATION_NOTEQUALS:
                if ($value != $this->conditionValue) {
                    return true;
                }
                break;
            case $this::OPERATION_STRICTNOTEQUALS:
                if ($value !== $this->conditionValue) {
                    return true;
                }
                break;
            case $this::OPERATION_STRICTEQUALS:
                if ($value === $this->conditionValue) {
                    return true;
                }
                break;
        }

        return false;
    }
}