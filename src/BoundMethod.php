<?php

namespace Jasmine\container;

use Closure;
use InvalidArgumentException;
use Jasmine\container\interfaces\ContainerInterface;
use Jasmine\exception\BindingResolutionException;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

class BoundMethod
{
    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param ContainerInterface $container
     * @param callable|string $callback
     * @param array $parameters
     * @param string|null $defaultMethod
     * @return mixed
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    public static function call(ContainerInterface $container, $callback, array $parameters = [], string $defaultMethod = null)
    {
        if (is_string($callback) && ! $defaultMethod && method_exists($callback, '__invoke')) {
            $defaultMethod = '__invoke';
        }

        if (static::isCallableWithAtSign($callback) || $defaultMethod) {
            return static::callClass($container, $callback, $parameters, $defaultMethod);
        }

        return static::callBoundMethod($container, $callback, function () use ($container, $callback, $parameters) {
            return $callback(...array_values(static::getMethodDependencies($container, $callback, $parameters)));
        });
    }

    /**
     * Call a string reference to a class using Class@method syntax.
     *
     * @param ContainerInterface $container
     * @param string $target
     * @param array $parameters
     * @param string|null $defaultMethod
     * @return mixed
     *
     * @throws ReflectionException
     * @throws BindingResolutionException
     */
    protected static function callClass(ContainerInterface $container, string $target, array $parameters = [], string $defaultMethod = null)
    {
        $segments = explode('@', $target);

        // We will assume an @ sign is used to delimit the class name from the method
        // name. We will split on this @ sign and then build a callable array that
        // we can pass right back into the "call" method for dependency binding.
        $method = count($segments) === 2
            ? $segments[1] : $defaultMethod;

        if (is_null($method)) {
            throw new InvalidArgumentException('Method not provided.');
        }

        return static::call(
            $container, [$container->make($segments[0]), $method], $parameters
        );
    }

    /**
     * Call a method that has been bound to the container.
     *
     * @param  ContainerInterface  $container
     * @param callable $callback
     * @param  mixed  $default
     * @return mixed
     */
    protected static function callBoundMethod(ContainerInterface $container, callable $callback, $default)
    {
        if (! is_array($callback)) {
            return Util::unwrapIfClosure($default);
        }

        // Here we need to turn the array callable into a Class@method string we can use to
        // examine the container and see if there are any method bindings for this given
        // method. If there are, we can call this method binding callback immediately.
        $method = static::normalizeMethod($callback);

        if ($container->hasMethodBinding($method)) {
            return $container->callMethodBinding($method, $callback[0]);
        }

        return Util::unwrapIfClosure($default);
    }

    /**
     * Normalize the given callback into a Class@method string.
     *
     * @param callable $callback
     * @return string
     */
    protected static function normalizeMethod(callable $callback): string
    {
        return sprintf("%s@%s",is_string($callback[0]) ? $callback[0] : get_class($callback[0]),$callback[1]);
    }

    /**
     * Get all dependencies for a given method.
     *
     * @param ContainerInterface $container
     * @param callable|string $callback
     * @param array $parameters
     * @return array
     *
     * @throws ReflectionException
     * @throws BindingResolutionException
     */
    protected static function getMethodDependencies(ContainerInterface $container, $callback, array $parameters = []): array
    {
        $dependencies = [];

        foreach (static::getCallReflector($callback)->getParameters() as $parameter) {
            static::addDependencyForCallParameter($container, $parameter, $parameters, $dependencies);
        }

        return array_merge($dependencies, array_values($parameters));
    }

    /**
     * Get the proper reflection instance for the given callback.
     *
     * @param callable|string $callback
     * @return ReflectionFunctionAbstract
     *
     * @throws ReflectionException
     */
    protected static function getCallReflector($callback)
    {
        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        } elseif (is_object($callback) && ! $callback instanceof Closure) {
            $callback = [$callback, '__invoke'];
        }

        return is_array($callback)
            ? new ReflectionMethod($callback[0], $callback[1])
            : new ReflectionFunction($callback);
    }

    /**
     * Get the dependency for the given call parameter.
     *
     * @param ContainerInterface $container
     * @param ReflectionParameter $parameter
     * @param array $parameters
     * @param array $dependencies
     * @return void
     * @throws BindingResolutionException
     * @throws ReflectionException
     */
    protected static function addDependencyForCallParameter(ContainerInterface $container, ReflectionParameter $parameter,
                                                            array              &$parameters, array &$dependencies)
    {
        if (array_key_exists($paramName = $parameter->getName(), $parameters)) {
            $dependencies[] = $parameters[$paramName];

            unset($parameters[$paramName]);
        } elseif (! is_null($className = Util::getParameterClassName($parameter))) {
            if (array_key_exists($className, $parameters)) {
                $dependencies[] = $parameters[$className];

                unset($parameters[$className]);
            } else {
                if ($parameter->isVariadic()) {
                    $variadicDependencies = $container->make($className);

                    $dependencies = array_merge($dependencies, is_array($variadicDependencies)
                        ? $variadicDependencies
                        : [$variadicDependencies]);
                } else {
                    $dependencies[] = $container->make($className);
                }
            }
        } elseif ($parameter->isDefaultValueAvailable()) {
            $dependencies[] = $parameter->getDefaultValue();
        } elseif (! $parameter->isOptional() && ! array_key_exists($paramName, $parameters)) {
            $message = sprintf("Unable to resolve dependency [%s] in class %s", $parameter, $parameter->getDeclaringClass()->getName());

            throw new BindingResolutionException($message);
        }
    }

    /**
     * Determine if the given string is in Class@method syntax.
     *
     * @param  mixed  $callback
     * @return bool
     */
    protected static function isCallableWithAtSign($callback): bool
    {
        return is_string($callback) && strpos($callback, '@') !== false;
    }
}