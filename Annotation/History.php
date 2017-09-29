<?php

namespace Resomedia\EntityHistoryBundle\Annotation;
use Doctrine\Common\Annotations\Annotation\Target;

/**
 * The History class handles the @History annotation.
 * @Annotation
 * @Target("CLASS")
 */
class History {
    /**
     * Parameter propertyOrigin
     * @var string
     */
    public $propertyOrigin;
}