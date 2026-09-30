<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class GetPromptResultResponse extends \SiteAgent\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'GetPromptResultResponse';

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
     * @return \SiteAgent\Vendor\WP\McpSchema\Record\GetPromptResult|\SiteAgent\Vendor\WP\McpSchema\Record\InputRequiredResult
     */
    public function getResult()
    {
        /** @var \SiteAgent\Vendor\WP\McpSchema\Record\GetPromptResult|\SiteAgent\Vendor\WP\McpSchema\Record\InputRequiredResult $value */
        $value = $this->declaredValue('result');

        return $value;
    }
}
