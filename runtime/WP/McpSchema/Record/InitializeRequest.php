<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class InitializeRequest extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ClientRequest
{
    public const DEFINITION = 'InitializeRequest';

    /**
     * @return float|int|string
     */
    public function getId()
    {
        /** @var float|int|string $value */
        $value = $this->declaredValue('id');

        return $value;
    }

    /**
     * @return '2.0'
     */
    public function getJsonrpc(): string
    {
        /** @var '2.0' $value */
        $value = $this->declaredValue('jsonrpc');

        return $value;
    }

    /**
     * @return 'initialize'
     */
    public function getMethod(): string
    {
        /** @var 'initialize' $value */
        $value = $this->declaredValue('method');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\InitializeRequestParams
     */
    public function getParams(): \SiteAgent\Vendor\WP\McpSchema\Record\InitializeRequestParams
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\InitializeRequestParams $value */
        $value = $this->declaredValue('params');

        return $value;
    }
}
