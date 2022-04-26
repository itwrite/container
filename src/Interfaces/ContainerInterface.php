<?php

namespace Jasmine\container\interfaces;

use Closure;
use Jasmine\exception\BindingResolutionException;
use LogicException;
use ReflectionException;

interface ContainerInterface
{

    /**
     * Register a binding with the container.
     *
     * @param string $abstract
     * @param  Closure|string|null  $concrete
     * @param bool $shared
     * @return void
     */
    public function bind(string $abstract, $concrete = null, bool $shared = false);

    /**
     * Register a binding if it hasn't already been registered.
     * @param mixed $abstract
     * @param null $concrete
     * @param bool $shared
     * @throws BindingResolutionException
     * @throws ReflectionException
     * itwri 2020/3/31 20:58
     */
    public function bindIf($abstract, $concrete = null, bool $shared = false);

    /**
     * Determine if the given abstract type has been bound.
     *
     * @param  mixed  $abstract
     * @return bool
     */
    public function bound($abstract): bool;

    /**
     * Register a shared binding in the container.
     *
     * @param  mixed  $abstract
     * @param  Closure|string|null  $concrete
     * @return void
     */
    public function singleton($abstract, $concrete = null);

    /**
     * Determine if the given abstract type has been resolved.
     *
     * @param string $abstract
     * @return bool
     */
    public function resolved(string $abstract): bool;

    /**
     * Determine if a given string is an alias.
     *
     * @param string $name
     * @return bool
     */
    public function isAlias(string $name): bool;

    /**
     * Get the alias for an abstract if available.
     *
     * @param string $abstract
     * @return string
     *
     * @throws LogicException
     */
    public function getAlias(string $abstract): string;

    /**
     * Determine if a given type is shared.
     *
     * @param string $abstract
     * @return bool
     */
    public function isShared(string $abstract): bool;

    /**
     * Resolve the given type from the container.
     * @param $abstract
     * @param array $parameters
     * @return mixed|object
     * @throws BindingResolutionException
     * @throws ReflectionException
     * itwri 2020/3/31 20:51
     */
    public function make($abstract, array $parameters = []);

    /**
     * "Extend" an abstract type in the container.
     * @param  $abstract
     * @param Closure $closure
     * @throws BindingResolutionException
     * @throws ReflectionException
     * itwri 2020/3/31 20:57
     */
    public function extend($abstract, Closure $closure);

    /**
     * Register an existing instance as shared in the container.
     *
     * @param string $abstract
     * @param mixed $instance
     * @return mixed
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public function instance(string $abstract, $instance);

    /**
     * Flush the container of all bindings and resolved instances.
     *
     * @return void
     */
    public function flush();

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     */
    public function call($callback, array $parameters = [], string $defaultMethod = null);

    /**
     * Register a new before resolving callback for all types.
     *
     * @param  Closure|string  $abstract
     * @param  Closure|null  $callback
     * @return void
     */
    public function beforeResolving($abstract, Closure $callback = null);

    /**
     * Register a new resolving callback.
     *
     * @param  Closure|string  $abstract
     * @param  Closure|null  $callback
     * @return void
     */
    public function resolving($abstract, Closure $callback = null);

    /**
     * Register a new after resolving callback for all types.
     *
     * @param  Closure|string  $abstract
     * @param  Closure|null  $callback
     * @return void
     */
    public function afterResolving($abstract, Closure $callback = null);
}