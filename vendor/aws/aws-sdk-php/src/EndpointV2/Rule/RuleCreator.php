<?php

namespace WPFitter\Aws\EndpointV2\Rule;

use WPFitter\Aws\Exception\UnresolvedEndpointException;
/** @internal */
class RuleCreator
{
    public static function create($type, $definition)
    {
        switch ($type) {
            case 'endpoint':
                return new EndpointRule($definition);
            case 'error':
                return new ErrorRule($definition);
            case 'tree':
                return new TreeRule($definition);
            default:
                throw new UnresolvedEndpointException('Unknown rule type ' . $type . ' must be of type `endpoint`, `tree` or `error`');
        }
    }
}
