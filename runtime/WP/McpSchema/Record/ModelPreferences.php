<?php

/**
 * This file is generated. Do not edit it directly.
 */

declare(strict_types=1);

namespace SiteAgent\Vendor\WP\McpSchema\Record;

final class ModelPreferences extends \SiteAgent\Vendor\WP\McpSchema\Record
{
    public const DEFINITION = 'ModelPreferences';

    /**
     * @return float|int|null
     */
    public function getCostPriority()
    {
        /** @var float|int|null $value */
        $value = $this->declaredValue('costPriority');

        return $value;
    }

    /**
     * @return array<int, \SiteAgent\Vendor\WP\McpSchema\Record\ModelHint>|null
     */
    public function getHints(): ?array
    {
        /** @var array<int, \SiteAgent\Vendor\WP\McpSchema\Record\ModelHint>|null $value */
        $value = $this->declaredValue('hints');

        return $value;
    }

    /**
     * @return float|int|null
     */
    public function getIntelligencePriority()
    {
        /** @var float|int|null $value */
        $value = $this->declaredValue('intelligencePriority');

        return $value;
    }

    /**
     * @return float|int|null
     */
    public function getSpeedPriority()
    {
        /** @var float|int|null $value */
        $value = $this->declaredValue('speedPriority');

        return $value;
    }
}
