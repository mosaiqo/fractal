<?php

/*
 * This file is part of the League\Fractal package.
 *
 * (c) Phil Sturgeon <me@philsturgeon.uk>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 **/

namespace League\Fractal;

use InvalidArgumentException;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use League\Fractal\Resource\Locale;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\Serializer\SerializerAbstract;

/**
 * Scope
 *
 * The scope class acts as a tracker, relating a specific resource in a specific
 * context. For example, the same resource could be attached to multiple scopes.
 * There are root scopes, parent scopes and child scopes.
 */
class Scope
{
    /**
     * @var array
     */
    protected $availableIncludes = [];

    /**
     * @var string
     */
    protected $scopeIdentifer;

    /**
     * @var \League\Fractal\Manager
     */
    protected $manager;

    /**
     * @var ResourceInterface
     */
    protected $resource;

    /**
     * @var array
     */
    protected $parentScopes = [];

    /**
     * Create a new scope instance.
     *
     * @param Manager           $manager
     * @param ResourceInterface $resource
     * @param string            $scopeIdentifer
     *
     * @return void
     */
    public function __construct(Manager $manager, ResourceInterface $resource, $scopeIdentifer = null)
    {
        $this->manager = $manager;
        $this->resource = $resource;
        $this->scopeIdentifer = $scopeIdentifer;
    }

    /**
     * Embed a scope as a child of the currenct scope.
     *
     * @internal
     *
     * @param string            $scopeIdentifier
     * @param ResourceInterface $resource
     *
     * @return \League\Fractal\Scope
     */
    public function embedChildScope($scopeIdentifier, $resource)
    {
        return $this->manager->createData($resource, $scopeIdentifier, $this);
    }

    /**
     * Get the current identifier.
     *
     * @return string
     */
    public function getScopeIdentifier()
    {
        return $this->scopeIdentifer;
    }

    /**
     * Get the unique identifier for this scope.
     *
     * @param string $appendIdentifier
     *
     * @return string
     */
    public function getIdentifier($appendIdentifier = null)
    {
        $identifierParts = array_merge($this->parentScopes, [$this->scopeIdentifer, $appendIdentifier]);

        return implode('.', array_filter($identifierParts));
    }

    /**
     * Getter for parentScopes.
     *
     * @return mixed
     */
    public function getParentScopes()
    {
        return $this->parentScopes;
    }

    /**
     * Getter for manager.
     *
     * @return \League\Fractal\Manager
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * Is Requested.
     *
     * Check if - in relation to the current scope - this specific segment is allowed.
     * That means, if a.b.c is requested and the current scope is a.b, then c is allowed. If the current
     * scope is a then c is not allowed, even if it is there and potentially transformable.
     *
     * @internal
     *
     * @param string $checkScopeSegment
     *
     * @return bool Returns the new number of elements in the array.
     */
    public function isRequested($checkScopeSegment)
    {
        if ($this->parentScopes) {
            $scopeArray = array_slice($this->parentScopes, 1);
            array_push($scopeArray, $this->scopeIdentifer, $checkScopeSegment);
        } else {
            $scopeArray = [$checkScopeSegment];
        }

        $scopeString = implode('.', (array) $scopeArray);

        $checkAgainstArray = $this->manager->getRequestedIncludes();

        return in_array($scopeString, $checkAgainstArray);
    }

    /**
     * Push Parent Scope.
     *
     * Push a scope identifier into parentScopes
     *
     * @internal
     *
     * @param string $identifierSegment
     *
     * @return int Returns the new number of elements in the array.
     */
    public function pushParentScope($identifierSegment)
    {
        return array_push($this->parentScopes, $identifierSegment);
    }

    /**
     * Set parent scopes.
     *
     * @internal
     *
     * @param string[] $parentScopes Value to set.
     *
     * @return $this
     */
    public function setParentScopes($parentScopes)
    {
        $this->parentScopes = $parentScopes;

        return $this;
    }

    /**
     * Convert the current data for this scope to an array.
     *
     * @return array
     */
    public function toArray()
    {
        list($rawData, $rawIncludedData) = $this->executeResourceTransformers();

        $serializer = $this->manager->getSerializer();

        $data = $this->serializeResource($serializer, $rawData);

        // If the serializer wants the includes to be side-loaded then we'll
        // serialize the included data and merge it with the data.
        if ($serializer->sideloadIncludes()) {
            $includedData = $serializer->includedData($this->resource, $rawIncludedData);

            $data = array_merge($data, $includedData);
        }

        if ($this->resource instanceof Collection)
        {
            if ($this->resource->hasCursor()){
                $pagination = $serializer->cursor($this->resource->getCursor());
            }
            elseif ($this->resource->hasPaginator())
            {
                $pagination = $serializer->paginator($this->resource->getPaginator());
            }
            else
            {
                $this->resource->setMetaValue( 'count', count($rawData) );
            }

            if (! empty($pagination)) {
                $this->resource->setMetaValue(key($pagination), current($pagination));
            }

            $meta = $this->resource->getMeta();

            if(!isset($meta['url']))
            {
                $this->resource->setMetaValue( 'url', $this->resource->getTransformer()
                  ->setMetaUrl());
            }

        }

        // If it' an item we include the the resource to the URL
        if($this->resource instanceof Item)
        {
            $item = $this->resource->getData();
            $this->resource->setMetaValue( 'url', $this->resource->getTransformer()
              ->setMetaUrl( $item ) );
        }


        // Pull out all of OUR metadata and any custom meta data to merge with the main level data
        $meta = $serializer->meta($this->resource->getMeta());


        return array_merge($data, $meta);
    }

    /**
     * Convert the current data for this scope to JSON.
     *
     * @return string
     */
    public function toJson()
    {
        return json_encode($this->toArray());
    }

    /**
     * Execute the resources transformer and return the data and included data.
     *
     * @internal
     *
     * @return array
     */
    protected function executeResourceTransformers()
    {
        $transformer = $this->resource->getTransformer();
        $data = $this->resource->getData();

        $transformedData = $includedData = [];

        if ($this->resource instanceof Item)
        {
            list( $transformedData, $includedData[] ) = $this->fireTransformer( $transformer, $data );
        } elseif ($this->resource instanceof Locale) {
            list($transformedData, $includedData[]) = $this->fireTransformer($transformer, $data);
        } elseif ($this->resource instanceof Collection) {
            foreach ($data as $value) {
                list($transformedData[], $includedData[]) = $this->fireTransformer($transformer, $value);
            }
        } else {
            throw new InvalidArgumentException(
                'Argument $resource should be an instance of League\Fractal\Resource\Item'
                .' or League\Fractal\Resource\Collection'
            );
        }

        return [$transformedData, $includedData];
    }

    /**
     * Serialize a resource
     *
     * @internal
     *
     * @param SerializerAbstract $serializer
     * @param mixed              $data
     *
     * @return array
     */
    protected function serializeResource(SerializerAbstract $serializer, $data)
    {
        $resourceKey = $this->resource->getResourceKey();

        if ($this->resource instanceof Collection) {
            return $serializer->collection($resourceKey, $data);
        } else {
            return $serializer->item($resourceKey, $data);
        }
    }

    /**
     * Fire the main transformer.
     *
     * @internal
     *
     * @param TransformerAbstract|callable $transformer
     * @param mixed                        $data
     *
     * @return array
     */
    protected function fireTransformer($transformer, $data)
    {
        $includedData = [];

        if (is_callable($transformer)) {
            $transformedData = call_user_func($transformer, $data);
        } else {
            $transformedData = $transformer->transform($data);
        }

        if ($this->transformerHasIncludes($transformer)) {
            $includedData = $this->fireIncludedTransformers($transformer, $data);

            // If the serializer does not want the includes to be side-loaded then
            // the included data must be merged with the transformed data.
            if (! $this->manager->getSerializer()->sideloadIncludes()) {
                $transformedData = array_merge($transformedData, $includedData);
            }
        }

        return [$transformedData, $includedData];
    }

    /**
     * Fire the included transformers.
     *
     * @internal
     *
     * @param \League\Fractal\TransformerAbstract $transformer
     * @param mixed                               $data
     *
     * @return array
     */
    protected function fireIncludedTransformers($transformer, $data)
    {
        $this->availableIncludes = $transformer->getAvailableIncludes();

        return $transformer->processIncludedResources($this, $data) ?: [];
    }

    /**
     * Determine if a transformer has any available includes.
     *
     * @internal
     *
     * @param TransformerAbstract|callable $transformer
     *
     * @return bool
     */
    protected function transformerHasIncludes($transformer)
    {
        if (! $transformer instanceof TransformerAbstract) {
            return false;
        }

        $defaultIncludes = $transformer->getDefaultIncludes();
        $availableIncludes = $transformer->getAvailableIncludes();

        return ! empty($defaultIncludes) || ! empty($availableIncludes);
    }
}
