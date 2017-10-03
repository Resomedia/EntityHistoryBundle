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
    const OPERATION_MORE = 1;
    const OPERATION_LESS = 2;
    const OPERATION_MOREEQUALS = 3;
    const OPERATION_LESSEQUALS = 4;
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
    protected $conditionValue;

    /**
     * Parameter operation
     * @var integer
     */
    protected $operation;

    /**
     * @param mixed $value
     * @return boolean
     */
    public function getOrigin($value)
    {
        if ($this->conditionValue === null || $this->operation === null) {
            return true;
        }
        if ($this->conditionValue == 'NULL') {
            $this->conditionValue = null;
        }
        switch ($this->operation) {
            case $this::OPERATION_EQUALS:
                if ($value == $this->conditionValue) {
                    return true;
                }
                break;
            case $this::OPERATION_MORE:
                if ($value > $this->conditionValue) {
                    return true;
                }
                break;
            case $this::OPERATION_LESS:
                if ($value < $this->conditionValue) {
                    return true;
                }
                break;
            case $this::OPERATION_MOREEQUALS:
                if ($value >= $this->conditionValue) {
                    return true;
                }
                break;
            case $this::OPERATION_LESSEQUALS:
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