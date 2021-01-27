<?php
/**
 * Created by PhpStorm.
 * User: itwri
 * Date: 2020/3/31
 * Time: 14:19
 */

namespace Jasmine\Container;

use Jasmine\Container\Interfaces\ContextualBindingBuilderInterface;

class ContextualBindingBuilder implements ContextualBindingBuilderInterface
{
    /**
     * The underlying container instance.
     *
     * @var Container
     */
    protected $container;

    /**
     * The concrete instance.
     *
     * @var string
     */
    protected $concrete;

    /**
     * The abstract target.
     *
     * @var string
     */
    protected $needs;

    /**
     * Create a new contextual binding builder.
     *
     * @param  Container  $container
     * @param  string  $concrete
     * @return void
     */
    public function __construct(Container $container, $concrete)
    {
        $this->concrete = $concrete;
        $this->container = $container;
    }

    /**
     * Define the abstract target that depends on the context.
     *
     * @param  string  $abstract
     * @return $this
     */
    public function needs($abstract)
    {
        $this->needs = $abstract;

        return $this;
    }

    /**
     * Define the implementation for the contextual binding.
     *
     * @param  \Closure|string  $implementation
     * @return void
     */
    public function give($implementation)
    {
        $this->container->addContextualBinding(
            $this->concrete, $this->needs, $implementation
        );
    }
}