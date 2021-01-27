<?php
/**
 * Created by PhpStorm.
 * User: itwri
 * Date: 2020/3/31
 * Time: 14:11
 */

namespace Jasmine\Container\Interfaces;


interface ContextualBindingBuilderInterface
{
    /**
     * Define the abstract target that depends on the context.
     *
     * @param  string  $abstract
     * @return $this
     */
    public function needs($abstract);

    /**
     * Define the implementation for the contextual binding.
     *
     * @param  \Closure|string  $implementation
     * @return void
     */
    public function give($implementation);
}