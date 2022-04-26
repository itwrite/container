<?php

namespace Jasmine\container;

use Closure;
use Jasmine\container\interfaces\ContainerInterface;
use Jasmine\exception\BindingResolutionException;
use LogicException;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

class Container implements ContainerInterface
{
    protected static $instance = null;
    /**
     * The container's bindings.
     *
     * @var array
     */
    protected $bindings = [];

    /**
     * The container's shared instances.
     *
     * @var array
     */
    protected $instances = [];

    /**
     * The registered type aliases.
     *
     * @var array
     */
    protected $aliases = [];

    /**
     * The registered aliases keyed by the abstract name.
     *
     * @var array
     */
    protected $abstractAliases = [];

    /**
     * The stack of concretions currently being built.
     *
     * @var array
     */
    protected $buildStack = [];

    /**
     * The parameter override stack.
     *
     * @var array
     */
    protected $with = [];

    /**
     * The contextual binding map.
     *
     * @var array
     */
    public $contextual = [];

    /**
     * An array of the types that have been resolved.
     *
     * @var array
     */
    protected $resolved = [];

    /**
     * The extension closures for services.
     *
     * @var array
     */
    protected $extenders = [];

    /**
     * All of the global before resolving callbacks.
     *
     * @var \Closure[]
     */
    protected $globalBeforeResolvingCallbacks = [];

    /**
     * All of the global resolving callbacks.
     *
     * @var \Closure[]
     */
    protected $globalResolvingCallbacks = [];

    /**
     * All the global after resolving callbacks.
     *
     * @var Closure[]
     */
    protected $globalAfterResolvingCallbacks = [];

    /**
     * All the before resolving callbacks by class type.
     *
     * @var array[]
     */
    protected $beforeResolvingCallbacks = [];

    /**
     * All the resolving callbacks by class type.
     *
     * @var array[]
     */
    protected $resolvingCallbacks = [];

    /**
     * All the after resolving callbacks by class type.
     *
     * @var array[]
     */
    protected $afterResolvingCallbacks = [];

    /**
     * The container's scoped instances.
     *
     * @var array
     */
    protected $scopedInstances = [];

    /**
     * The container's method bindings.
     *
     * @var Closure[]
     */
    protected $methodBindings = [];


    /**
     * @throws ReflectionException
     * @throws BindingResolutionException
     */
    public function bind(string $abstract, $concrete = null, bool $shared = false)
    {
        // If no concrete type was given, we will simply set the concrete type to the
        // abstract type. After that, the concrete type to be registered as shared
        // without being forced to state their classes in both of the parameters.
        $this->dropStaleInstances($abstract);

        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        // If the factory is not a Closure, it means it is just a class name which is
        // bound into this container to the abstract type, and we will just wrap it
        // up inside its own Closure to give us more convenience when extending.
        if (! $concrete instanceof Closure) {

            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');


        // If the abstract type was already resolved in this container we'll fire the
        // rebound listener so that any objects which have already gotten resolved
        // can have their copy of the object updated via the listener callbacks.
        if ($this->resolved($abstract)) {
            $this->rebound($abstract);
        }
    }

    /**
     * Register a binding if it hasn't already been registered.
     * @param $abstract
     * @param $concrete
     * @param bool $shared
     * @return void
     * @throws BindingResolutionException
     * @throws ReflectionException
     * @author zzp
     * @date 2022/4/15
     */
    public function bindIf($abstract, $concrete = null, bool $shared = false)
    {
        if (! $this->bound($abstract)) {
            $this->bind($abstract, $concrete, $shared);
        }
    }

    /**
     * Determine if the given abstract type has been bound.
     *
     * @param  mixed  $abstract
     * @return bool
     */
    public function bound($abstract): bool
    {
        return isset($this->bindings[$abstract]) ||
            isset($this->instances[$abstract]) ||
            $this->isAlias($abstract);
    }

    /**
     * Register a shared binding in the container.
     * @param $abstract
     * @param $concrete
     * @return void
     * @throws BindingResolutionException
     * @throws ReflectionException
     * @author zzp
     * @date 2022/4/15
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Register a shared binding if it hasn't already been registered.
     *
     * @param $abstract
     * @param Closure|string|null $concrete
     * @return void
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public function singletonIf($abstract, $concrete = null)
    {
        if (! $this->bound($abstract)) {
            $this->singleton($abstract, $concrete);
        }
    }

    /**
     * Register a scoped binding in the container.
     *
     * @param  $abstract
     * @param Closure|string|null $concrete
     * @return void
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public function scoped($abstract, $concrete = null)
    {
        $this->scopedInstances[] = $abstract;

        $this->singleton($abstract, $concrete);
    }

    /**
     * Register a scoped binding if it hasn't already been registered.
     *
     * @param  $abstract
     * @param  Closure|string|null $concrete
     * @return void
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public function scopedIf($abstract, $concrete = null)
    {
        if (! $this->bound($abstract)) {
            $this->scopedInstances[] = $abstract;

            $this->singleton($abstract, $concrete);
        }
    }

    /**
     * Determine if the given abstract type has been resolved.
     *
     * @param string $abstract
     * @return bool
     */
    public function resolved(string $abstract): bool
    {
        if ($this->isAlias($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        return isset($this->resolved[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * Determine if a given string is an alias.
     *
     * @param string $name
     * @return bool
     */
    public function isAlias(string $name): bool
    {
        return isset($this->aliases[$name]);
    }

    /**
     * Get the alias for an abstract if available.
     *
     * @param string $abstract
     * @return string
     *
     * @throws LogicException
     */
    public function getAlias(string $abstract): string
    {
        if (! isset($this->aliases[$abstract])) {
            return $abstract;
        }

        if ($this->aliases[$abstract] === $abstract) {
            throw new LogicException("[$abstract] is aliased to itself.");
        }

        return $this->getAlias($this->aliases[$abstract]);
    }

    /**
     * Determine if a given type is shared.
     *
     * @param string $abstract
     * @return bool
     */
    public function isShared(string $abstract): bool
    {
        return isset($this->instances[$abstract]) ||
            (isset($this->bindings[$abstract]['shared']) &&
                $this->bindings[$abstract]['shared'] === true);
    }

    /**
     * Drop all the stale instances and aliases.
     *
     * @param string $abstract
     * @return void
     */
    protected function dropStaleInstances(string $abstract)
    {
        unset($this->instances[$abstract], $this->aliases[$abstract]);
    }

    /**
     * Get the Closure to be used when building a type.
     *
     * @param mixed $abstract
     * @param mixed $concrete
     * @return Closure
     */
    protected function getClosure(string $abstract, $concrete): Closure
    {
        return function (Container $container, $parameters = []) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete);
            }
            return $container->make($concrete, $parameters);
        };
    }

    /**
     * Fire the "rebound" callbacks for the given abstract type.
     * @param $abstract
     * @throws BindingResolutionException
     * @throws ReflectionException
     * itwri 2020/3/31 20:56
     */
    protected function rebound($abstract)
    {
        $instance = $this->make($abstract);

        foreach ($this->getReboundCallbacks($abstract) as $callback) {
            call_user_func($callback, $this, $instance);
        }
    }

    /**
     * Get the rebound callbacks for a given type.
     *
     * @param  $abstract
     * @return array
     */
    protected function getReboundCallbacks($abstract): array
    {
        if (isset($this->reboundCallbacks[$abstract])) {
            return $this->reboundCallbacks[$abstract];
        }

        return [];
    }

    /**
     * "Extend" an abstract type in the container.
     * @param  $abstract
     * @param Closure $closure
     * @throws BindingResolutionException
     * @throws ReflectionException
     * itwri 2020/3/31 20:57
     */
    public function extend($abstract, Closure $closure)
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $closure($this->instances[$abstract], $this);

            $this->rebound($abstract);
        } else {
            $this->extenders[$abstract][] = $closure;

            if ($this->resolved($abstract)) {
                $this->rebound($abstract);
            }
        }
    }

    /**
     * Resolve the given type from the container.
     * @param $abstract
     * @param array $parameters
     * @return mixed|object
     * @throws BindingResolutionException
     * @throws ReflectionException
     * itwri 2020/3/31 20:51
     */
    public function make($abstract, array $parameters = [])
    {
        return $this->resolve($abstract, $parameters);
    }

    /**
     * Resolve the given type from the container.
     * @param $abstract
     * @param array $parameters
     * @return mixed|object
     * @throws BindingResolutionException
     * @throws ReflectionException
     * itwri 2020/3/31 14:46
     */
    protected function resolve($abstract, array $parameters = [])
    {
        $abstract = $this->getAlias($abstract);

        $needsContextualBuild = ! empty($parameters) || ! is_null(
                $this->getContextualConcrete($abstract)
            );

        // If an instance of the type is currently being managed as a singleton we'll
        // just return an existing instance instead of instantiating new instances
        // so the developer can keep using the same objects instance every time.
        if (isset($this->instances[$abstract]) && ! $needsContextualBuild) {
            return $this->instances[$abstract];
        }

        $this->with[] = $parameters;

        $concrete = $this->getConcrete($abstract);

        // We're ready to instantiate an instance of the concrete type registered for
        // the binding. This will instantiate the types, as well as resolve any of
        // its "nested" dependencies recursively until all have gotten resolved.
        if ($this->isBuildable($concrete, $abstract)) {
            $object = $this->build($concrete);
        } else {
            $object = $this->make($concrete);
        }

        // If we defined any extenders for this type, we'll need to spin through them
        // and apply them to the object being built. This allows for the extension
        // of services, such as changing configuration or decorating the object.
        foreach ($this->getExtenders($abstract) as $extender) {
            $object = $extender($object, $this);
        }

        // If the requested type is registered as a singleton we'll want to cache off
        // the instances in "memory" so we can return it later without creating an
        // entirely new instance of an object on each subsequent request for it.
        if ($this->isShared($abstract) && ! $needsContextualBuild) {
            $this->instances[$abstract] = $object;
        }

        $this->fireResolvingCallbacks($abstract, $object);

        // Before returning, we will also set the resolved flag to "true" and pop off
        // the parameter overrides for this build. After those two things are done
        // we will be ready to return the fully constructed class instance.
        $this->resolved[$abstract] = true;

        array_pop($this->with);

        return $object;
    }

    /**
     * Register a new before resolving callback for all types.
     *
     * @param  Closure|string  $abstract
     * @param  Closure|null  $callback
     * @return void
     */
    public function beforeResolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if ($abstract instanceof Closure && is_null($callback)) {
            $this->globalBeforeResolvingCallbacks[] = $abstract;
        } else {
            $this->beforeResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a new resolving callback.
     *
     * @param  Closure|string  $abstract
     * @param  Closure|null  $callback
     * @return void
     */
    public function resolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if (is_null($callback) && $abstract instanceof Closure) {
            $this->globalResolvingCallbacks[] = $abstract;
        } else {
            $this->resolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Register a new after resolving callback for all types.
     *
     * @param  Closure|string  $abstract
     * @param  Closure|null  $callback
     * @return void
     */
    public function afterResolving($abstract, Closure $callback = null)
    {
        if (is_string($abstract)) {
            $abstract = $this->getAlias($abstract);
        }

        if ($abstract instanceof Closure && is_null($callback)) {
            $this->globalAfterResolvingCallbacks[] = $abstract;
        } else {
            $this->afterResolvingCallbacks[$abstract][] = $callback;
        }
    }

    /**
     * Fire all the resolving callbacks.
     *
     * @param  $abstract
     * @param  mixed   $object
     * @return void
     */
    protected function fireResolvingCallbacks($abstract, $object)
    {
        $this->fireCallbackArray($object, $this->globalResolvingCallbacks);

        $this->fireCallbackArray(
            $object, $this->getCallbacksForType($abstract, $object, $this->resolvingCallbacks)
        );

        $this->fireAfterResolvingCallbacks($abstract, $object);
    }

    /**
     * Fire all the after resolving callbacks.
     *
     * @param  $abstract
     * @param  mixed   $object
     * @return void
     */
    protected function fireAfterResolvingCallbacks($abstract, $object)
    {
        $this->fireCallbackArray($object, $this->globalAfterResolvingCallbacks);

        $this->fireCallbackArray($object, $this->getCallbacksForType($abstract, $object, $this->afterResolvingCallbacks));
    }

    /**
     * Get all callbacks for a given type.
     *
     * @param  $abstract
     * @param  object  $object
     * @param  array   $callbacksPerType
     *
     * @return array
     */
    protected function getCallbacksForType($abstract, $object, array $callbacksPerType): array
    {
        $results = [];

        foreach ($callbacksPerType as $type => $callbacks) {
            if ($type === $abstract || $object instanceof $type) {
                $results = array_merge($results, $callbacks);
            }
        }

        return $results;
    }

    /**
     * Fire an array of callbacks with an object.
     *
     * @param  mixed  $object
     * @param  array  $callbacks
     * @return void
     */
    protected function fireCallbackArray($object, array $callbacks)
    {
        foreach ($callbacks as $callback) {
            $callback($object, $this);
        }
    }

    /**
     * Get the extender callbacks for a given type.
     *
     * @param  $abstract
     * @return array
     */
    protected function getExtenders($abstract): array
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->extenders[$abstract])) {
            return $this->extenders[$abstract];
        }

        return [];
    }

    /**
     * Determine if the given concrete is buildable.
     *
     * @param  mixed   $concrete
     * @param  mixed  $abstract
     * @return bool
     */
    protected function isBuildable($concrete, $abstract): bool
    {
        return $concrete === $abstract || $concrete instanceof Closure;
    }

    /**
     * Instantiate a concrete instance of the given type.
     * @param $concrete
     * @return mixed|object
     * @throws BindingResolutionException
     * @throws ReflectionException
     * itwri 2020/3/31 14:32
     */
    public function build($concrete)
    {
        // If the concrete type is actually a Closure, we will just execute it and
        // hand back the results of the functions, which allows functions to be
        // used as resolvers for more fine-tuned resolution of these objects.
        if ($concrete instanceof Closure) {
            return $concrete($this, $this->getLastParameterOverride());
        }

        $reflector = new ReflectionClass($concrete);

        // If the type is not instantiable, the developer is attempting to resolve
        // an abstract type such as an Interface of Abstract Class and there is
        // no binding registered for the abstractions, so we need to bail out.
        if (! $reflector->isInstantiable()) {
            return $this->notInstantiable($concrete);
        }

        $this->buildStack[] = $concrete;

        $constructor = $reflector->getConstructor();

        // If there are no constructors, that means there are no dependencies then
        // we can just resolve the instances of the objects right away, without
        // resolving any other types or dependencies out of these containers.
        if (is_null($constructor)) {
            array_pop($this->buildStack);

            return new $concrete;
        }

        $dependencies = $constructor->getParameters();

        // Once we have all the constructor's parameters we can create each of the
        // dependency instances and then use the reflection instances to make a
        // new instance of this class, injecting the created dependencies in.
        $instances = $this->resolveDependencies(
            $dependencies
        );

        array_pop($this->buildStack);

        return $reflector->newInstanceArgs($instances);
    }

    /**
     * Resolve all the dependencies from the ReflectionParameters.
     * @param array $dependencies
     * @return array
     * @throws BindingResolutionException
     * @throws ReflectionException
     * itwri 2020/3/31 20:56
     */
    protected function resolveDependencies(array $dependencies): array
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            // If this dependency has an override for this particular build we will use
            // that instead as the value. Otherwise, we will continue with this run
            // of resolutions and let reflection attempt to determine the result.
            if ($this->hasParameterOverride($dependency)) {
                $results[] = $this->getParameterOverride($dependency);

                continue;
            }

            // If the class is null, it means the dependency is a string or some other
            // primitive type which we can not resolve since it is not a class, and
            // we will just bomb out with an error since we have no-where to go.
            $results[] = is_null($dependency->getClass())
                ? $this->resolvePrimitive($dependency)
                : $this->resolveClass($dependency);
        }
        return $results;
    }

    /**
     * Determine if the given dependency has a parameter override.
     *
     * @param  ReflectionParameter  $dependency
     * @return bool
     */
    protected function hasParameterOverride(ReflectionParameter $dependency): bool
    {
        return array_key_exists(
            $dependency->name, $this->getLastParameterOverride()
        );
    }

    /**
     * Get a parameter override for a dependency.
     *
     * @param  ReflectionParameter  $dependency
     * @return mixed
     */
    protected function getParameterOverride(ReflectionParameter $dependency)
    {
        return $this->getLastParameterOverride()[$dependency->name];
    }

    /**
     * Resolve a non-class hinted primitive dependency.
     * @param ReflectionParameter $parameter
     * @return mixed|null|string
     * @throws BindingResolutionException
     * itwri 2020/3/31 14:31
     */
    protected function resolvePrimitive(ReflectionParameter $parameter)
    {
        if (! is_null($concrete = $this->getContextualConcrete('$'.$parameter->name))) {
            return $concrete instanceof Closure ? $concrete($this) : $concrete;
        }

        if (!$parameter->isDefaultValueAvailable()) {
            //Throw an exception for an unresolvable primitive.
            throw new BindingResolutionException("Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}");
        }

        return $parameter->getDefaultValue();
    }

    /**
     * Resolve a class based dependency from the container.
     * @param ReflectionParameter $parameter
     * @return mixed|object
     * @throws BindingResolutionException
     * @throws ReflectionException
     * itwri 2020/3/31 20:52
     */
    protected function resolveClass(ReflectionParameter $parameter)
    {
        try {
            return $this->make($parameter->getClass()->name);
        }

            // If we can not resolve the class instance, we will check to see if the value
            // is optional, and if it is we will return the optional parameter value as
            // the value of the dependency, similarly to how we do this with scalars.
        catch (BindingResolutionException $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }

            throw $e;
        }
    }

    /**
     * Get the contextual concrete binding for the given abstract.
     *
     * @param   $abstract
     * @return mixed
     */
    protected function getContextualConcrete($abstract)
    {
        if (! is_null($binding = $this->findInContextualBindings($abstract))) {
            return $binding;
        }

        // Next we need to see if a contextual binding might be bound under an alias of the
        // given abstract type. So, we will need to check if any aliases exist with this
        // type and then spin through them and check for contextual bindings on these.
        if (empty($this->abstractAliases[$abstract])) {
            return null;
        }

        foreach ($this->abstractAliases[$abstract] as $alias) {
            if (! is_null($binding = $this->findInContextualBindings($alias))) {
                return $binding;
            }
        }
        return null;
    }

    /**
     * Get the last parameter override.
     *
     * @return array
     */
    protected function getLastParameterOverride(): array
    {
        return count($this->with) ? end($this->with) : [];
    }

    /**
     * Get the concrete type for a given abstract.
     *
     * @param  $abstract
     * @return mixed   $concrete
     */
    protected function getConcrete($abstract)
    {
        if (! is_null($concrete = $this->getContextualConcrete($abstract))) {
            return $concrete;
        }

        // If we don't have a registered resolver or concrete for the type, we'll just
        // assume each type is a concrete name and will attempt to resolve it as is
        // since the container should be able to resolve concretes automatically.
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
    }


    /**
     * Find the concrete binding for the given abstract in the contextual binding array.
     *
     * @param $abstract
     * @return string|null
     */
    protected function findInContextualBindings($abstract): ?string
    {
        if (isset($this->contextual[end($this->buildStack)][$abstract])) {
            return $this->contextual[end($this->buildStack)][$abstract];
        }
        return null;
    }

    /**
     * Throw an exception that the concrete is not instantiable.
     * @param $concrete
     * @throws BindingResolutionException
     * itwri 2020/3/31 14:30
     */
    protected function notInstantiable($concrete)
    {
        if (! empty($this->buildStack)) {
            $previous = implode(', ', $this->buildStack);

            $message = "Target [$concrete] is not instantiable while building [$previous].";
        } else {
            $message = "Target [$concrete] is not instantiable.";
        }

        throw new BindingResolutionException($message);
    }

    /**
     * Register an existing instance as shared in the container.
     *
     * @param string $abstract
     * @param mixed $instance
     * @return mixed
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public function instance(string $abstract, $instance)
    {
        $this->removeAbstractAlias($abstract);

        $isBound = $this->bound($abstract);

        unset($this->aliases[$abstract]);

        // We'll check to determine if this type has been bound before, and if it has
        // we will fire the rebound callbacks registered with the container, and it
        // can be updated with consuming classes that have gotten resolved here.
        $this->instances[$abstract] = $instance;

        if ($isBound) {
            $this->rebound($abstract);
        }

        return $instance;
    }

    /**
     * Remove an alias from the contextual binding alias cache.
     *
     * @param  $searched
     * @return void
     */
    protected function removeAbstractAlias($searched)
    {
        if (! isset($this->aliases[$searched])) {
            return;
        }

        foreach ($this->abstractAliases as $abstract => $aliases) {
            foreach ($aliases as $index => $alias) {
                if ($alias == $searched) {
                    unset($this->abstractAliases[$abstract][$index]);
                }
            }
        }
    }

    /**
     * Flush the container of all bindings and resolved instances.
     *
     * @return void
     */
    public function flush()
    {
        $this->aliases = [];
        $this->resolved = [];
        $this->bindings = [];
        $this->instances = [];
        $this->abstractAliases = [];
        $this->scopedInstances = [];
    }

    /**
     * Get the globally available instance of the container.
     * @return null|static
     * @author zzp
     * @date 2022/4/15
     */
    public static function getInstance(): ?Container
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }

        return static::$instance;
    }

    /**
     * Set the shared instance of the container.
     * @param ContainerInterface|null $container
     * @return ContainerInterface|null
     * @author zzp
     * @date 2022/4/15
     */
    public static function setInstance(ContainerInterface $container = null): ?ContainerInterface
    {
        return static::$instance = $container;
    }

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param callable|string $callback
     * @param array<string, mixed> $parameters
     * @param string|null $defaultMethod
     * @return mixed
     *
     * @throws ReflectionException|BindingResolutionException
     */
    public function call($callback, array $parameters = [], string $defaultMethod = null)
    {
        return BoundMethod::call($this, $callback, $parameters, $defaultMethod);
    }


    /**
     * Determine if the container has a method binding.
     *
     * @param string $method
     * @return bool
     */
    public function hasMethodBinding(string $method): bool
    {
        return isset($this->methodBindings[$method]);
    }

    /**
     * Bind a callback to resolve with Container::call.
     *
     * @param  array|string  $method
     * @param  Closure  $callback
     * @return void
     */
    public function bindMethod($method, Closure $callback)
    {
        $this->methodBindings[$this->parseBindMethod($method)] = $callback;
    }

    /**
     * Get the method to be bound in class@method format.
     *
     * @param $method
     * @return string
     */
    protected function parseBindMethod($method): string
    {
        if (is_array($method)) {
            return $method[0].'@'.$method[1];
        }

        return $method;
    }

    /**
     * Get the method binding for the given method.
     *
     * @param  string  $method
     * @param  mixed  $instance
     * @return mixed
     */
    public function callMethodBinding(string $method, $instance)
    {
        return call_user_func($this->methodBindings[$method], $instance, $this);
    }

    /**
     * Dynamically access container services.
     *
     * @param $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this[$key];
    }

    /**
     * Dynamically set container services.
     *
     * @param  $key
     * @param  mixed   $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this[$key] = $value;
    }
}