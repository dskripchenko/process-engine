<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Interpreter;

use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Definition\DefinitionError;
use Dskripchenko\ProcessEngine\Event;
use Dskripchenko\ProcessEngine\Support\Arguments;
use Dskripchenko\ProcessEngine\Support\EventRegistry;
use Dskripchenko\ProcessEngine\Support\Hydrator;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Calls methods of the process class (guards, context updates, inputs,
 * durations) with arguments injected by type: the context (object or
 * array), the event (object or payload array), StepContext, extras.
 *
 * @internal
 */
final class Methods
{
    /** @var array<class-string, object> */
    private static array $instances = [];

    /** @var array<string, ReflectionMethod> */
    private static array $reflections = [];

    public function __construct(private readonly Definition $definition) {}

    /**
     * @param  array<string, mixed>  $context  updated in place
     * @param  list<mixed>  $extras
     */
    public function call(string $method, array &$context, ?string $contextClass, ?Event $event, StepContext $ctx, array $extras = []): mixed
    {
        $process = $this->process();
        $reflection = $this->reflection($process, $method);

        $contextObject = $contextClass !== null && class_exists($contextClass)
            ? Hydrator::hydrate($contextClass, $context)
            : null;
        $objects = [StepContext::class => $ctx];
        if ($contextObject !== null) {
            $objects[$contextClass] = $contextObject;
        }
        if ($event !== null) {
            $objects[Event::class] = $event;
        }

        $named = ['context' => $context];
        if ($event !== null) {
            $named['event'] = $event->payload;
        }
        $factories = [];
        if ($event !== null) {
            $factories['event'] = function (string $class) use ($event): ?object {
                if (! class_exists($class)) {
                    return null;
                }
                $expected = EventRegistry::classFor($event->name);
                if ($expected !== null && ! is_a($expected, $class, true)) {
                    return null;
                }
                try {
                    return Hydrator::hydrate($class, $event->payload);
                } catch (Throwable) {
                    return null;
                }
            };
        }

        $args = Arguments::resolve($reflection, $objects, $named, $factories, $extras);
        $result = $reflection->invokeArgs($process, $args);

        $context = $contextObject !== null ? (array) Hydrator::dehydrate($contextObject) : $named['context'];

        return $result;
    }

    /**
     * Whether the method takes the event (so its result is unknown before the event).
     */
    public function readsEvent(string $method, ?string $contextClass): bool
    {
        foreach ($this->reflection($this->process(), $method)->getParameters() as $parameter) {
            if (in_array($parameter->getName(), ['event', 'e', 'payload'], true)) {
                return true;
            }
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $class = $type->getName();
            if (is_a($class, StepContext::class, true) || ($contextClass !== null && is_a($contextClass, $class, true))) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function process(): object
    {
        $class = $this->definition->processClass();
        if ($class === null || ! class_exists($class)) {
            throw new DefinitionError("The definition {$this->definition->key()} refers to methods but its process class is not available");
        }

        return self::$instances[$class] ??= new $class;
    }

    private function reflection(object $process, string $method): ReflectionMethod
    {
        $key = $process::class.'::'.$method;
        if (isset(self::$reflections[$key])) {
            return self::$reflections[$key];
        }
        if (! method_exists($process, $method)) {
            throw new DefinitionError($process::class." has no method `{$method}`");
        }

        return self::$reflections[$key] = new ReflectionMethod($process, $method);
    }
}
