<?php

namespace WPFitter\Aws\Sts\RegionalEndpoints;

/**
 * Provides access to STS regional endpoints configuration options: endpoints_type
 * @internal
 */
interface ConfigurationInterface
{
    /**
     * Returns the endpoints type
     *
     * @return string
     */
    public function getEndpointsType();
    /**
     * Returns the configuration as an associative array
     *
     * @return array
     */
    public function toArray();
}
