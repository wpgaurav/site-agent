<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class PingRequest extends \SiteAgent\Vendor\WP\McpSchema\Record implements \SiteAgent\Vendor\WP\McpSchema\Contract\ClientRequest, \SiteAgent\Vendor\WP\McpSchema\Contract\ServerRequest
{
    public const DEFINITION = 'PingRequest';

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
     * @return 'ping'
     */
    public function getMethod(): string
    {
        /** @var 'ping' $value */
        $value = $this->declaredValue('method');

        return $value;
    }

    /**
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\RequestParams|null
     */
    public function getParams(): ?\SiteAgent\Vendor\WP\McpSchema\Record\RequestParams
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\RequestParams|null $value */
        $value = $this->declaredValue('params');

        return $value;
    }
}
