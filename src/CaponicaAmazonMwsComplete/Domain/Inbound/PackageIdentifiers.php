<?php


namespace CaponicaAmazonMwsComplete\Domain\Inbound;

use CaponicaAmazonMwsComplete\Exceptions\MaxQuantityExceededException;
use InvalidArgumentException;

class PackageIdentifiers implements \CaponicaAmazonMwsComplete\Contracts\Arrayable
{

    const MAX_CARTON_ID_COUNT = 999;

    private $cartonIds = [];

    /**
     * PackageIdentifiers constructor.
     *
     * @param string|string[] $cartonIds
     *
     * @throws MaxQuantityExceededException
     */
    public function __construct($cartonIds)
    {
        if (is_string($cartonIds)) {
            $cartonIds = [$cartonIds];
        }

        if ( ! is_array($cartonIds)) {
            throw new InvalidArgumentException('Invalid $cartonIds$cartonIds argument type. Must be one of `string[]` or `string`.');
        }

        foreach ($cartonIds as $cartonId) {
            $this->add($cartonId);
        }
    }

    /**
     * @param string $cartonId
     *
     * @throws MaxQuantityExceededException
     */
    public function add($cartonId)
    {
        if (in_array($cartonId, $this->cartonIds)) {
            return;
        }

        if ( ! is_string($cartonId)) {
            throw new InvalidArgumentException('Invalid CartonId type. Must be `string`.');
        }

        if ( ! $this->canAddCartonId()) {
            throw new MaxQuantityExceededException('Max count of CartonId exceeded. Max count allowed: '
                . $this->getMaxCartonIdCount());
        }

        $this->cartonIds[] = $cartonId;
    }

    /**
     * @param string $cartonId
     */
    public function delete($cartonId)
    {
        $key = array_search($cartonId, $this->cartonIds);

        if ($key !== false) {
            unset($this->cartonIds[$key]);
        }
    }

    /**
     * @return int
     */
    public function getMaxCartonIdCount()
    {
        return self::MAX_CARTON_ID_COUNT;
    }

    /**
     * @return int
     */
    public function getCartonIdCount()
    {
        return count($this->cartonIds);
    }

    /**
     * @return bool
     */
    public function canAddCartonId()
    {
        return $this->getCartonIdCount() < $this->getMaxCartonIdCount();
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return $this->cartonIds;
    }
}