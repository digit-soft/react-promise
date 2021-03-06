<?php

namespace DigitSoft\Promise;

use React\Promise\CancellationQueue;
use React\Promise\ExtendedPromiseInterface;
use React\Promise\PromiseInterface;

function resolve($promiseOrValue = null, ChainDependencyInterface &$chainDependency = null)
{
    /** @var $promiseOrValue ExtendedPromiseInterface */
    if($promiseOrValue instanceof PromiseWithDependenciesInterface && isset($chainDependency)) {
        _mergeDependencies($chainDependency, $promiseOrValue->chainDependency, true);
        return $promiseOrValue;
    }

    /** @var $promiseOrValue PromiseInterface */
    if (method_exists($promiseOrValue, 'then')) {
        $canceller = null;

        if (method_exists($promiseOrValue, 'cancel')) {
            $canceller = [$promiseOrValue, 'cancel'];
        }

        return new Promise(function ($resolve, $reject, $notify) use ($promiseOrValue) {
            $promiseOrValue->then($resolve, $reject, $notify);
        }, $canceller, $chainDependency);
    }

    /** @var $promiseOrValue mixed */
    return new FulfilledPromise($promiseOrValue, $chainDependency);
}

function reject($promiseOrValue = null, ChainDependencyInterface $chainDependency = null)
{
    if ($promiseOrValue instanceof PromiseInterface) {
        return resolve($promiseOrValue, $chainDependency)->then(function ($value, $chainDependency = null) {
            return new RejectedPromise($value, $chainDependency);
        });
    }

    return new RejectedPromise($promiseOrValue, $chainDependency);
}

function all($promisesOrValues)
{
    return map($promisesOrValues, function ($val) {
        return $val;
    });
}

function race($promisesOrValues)
{
    $cancellationQueue = new CancellationQueue();
    $cancellationQueue->enqueue($promisesOrValues);

    return new Promise(function ($resolve, $reject, $notify) use ($promisesOrValues, $cancellationQueue) {
        resolve($promisesOrValues)
            ->done(function ($array) use ($cancellationQueue, $resolve, $reject, $notify) {
                if (!is_array($array) || !$array) {
                    $resolve();
                    return;
                }

                foreach ($array as $promiseOrValue) {
                    $cancellationQueue->enqueue($promiseOrValue);

                    resolve($promiseOrValue)
                        ->done($resolve, $reject, $notify);
                }
            }, $reject, $notify);
    }, $cancellationQueue);
}

function any($promisesOrValues)
{
    return some($promisesOrValues, 1)
        ->then(function ($val) {
            return array_shift($val);
        });
}

function some($promisesOrValues, $howMany)
{
    $cancellationQueue = new CancellationQueue();
    $cancellationQueue->enqueue($promisesOrValues);

    return new Promise(function ($resolve, $reject, $notify) use ($promisesOrValues, $howMany, $cancellationQueue) {
        resolve($promisesOrValues)
            ->done(function ($array) use ($howMany, $cancellationQueue, $resolve, $reject, $notify) {
                if (!is_array($array) || $howMany < 1) {
                    $resolve([]);
                    return;
                }

                $len = count($array);

                if ($len < $howMany) {
                    throw new \React\Promise\Exception\LengthException(
                        sprintf(
                            'Input array must contain at least %d item%s but contains only %s item%s.',
                            $howMany,
                            1 === $howMany ? '' : 's',
                            $len,
                            1 === $len ? '' : 's'
                        )
                    );
                }

                $toResolve = $howMany;
                $toReject  = ($len - $toResolve) + 1;
                $values    = [];
                $reasons   = [];

                foreach ($array as $i => $promiseOrValue) {
                    $fulfiller = function ($val) use ($i, &$values, &$toResolve, $toReject, $resolve) {
                        if ($toResolve < 1 || $toReject < 1) {
                            return;
                        }

                        $values[$i] = $val;

                        if (0 === --$toResolve) {
                            $resolve($values);
                        }
                    };

                    $rejecter = function ($reason) use ($i, &$reasons, &$toReject, $toResolve, $reject) {
                        if ($toResolve < 1 || $toReject < 1) {
                            return;
                        }

                        $reasons[$i] = $reason;

                        if (0 === --$toReject) {
                            $reject($reasons);
                        }
                    };

                    $cancellationQueue->enqueue($promiseOrValue);

                    resolve($promiseOrValue)
                        ->done($fulfiller, $rejecter, $notify);
                }
            }, $reject, $notify);
    }, $cancellationQueue);
}

function map($promisesOrValues, callable $mapFunc)
{
    $cancellationQueue = new CancellationQueue();
    $cancellationQueue->enqueue($promisesOrValues);

    return new Promise(function ($resolve, $reject, $notify) use ($promisesOrValues, $mapFunc, $cancellationQueue) {
        resolve($promisesOrValues)
            ->done(function ($array) use ($mapFunc, $cancellationQueue, $resolve, $reject, $notify) {
                if (!is_array($array) || !$array) {
                    $resolve([]);
                    return;
                }

                $toResolve = count($array);
                $values    = [];

                foreach ($array as $i => $promiseOrValue) {
                    $cancellationQueue->enqueue($promiseOrValue);
                    $values[$i] = null;

                    resolve($promiseOrValue)
                        ->then($mapFunc)
                        ->done(
                            function ($mapped) use ($i, &$values, &$toResolve, $resolve) {
                                $values[$i] = $mapped;

                                if (0 === --$toResolve) {
                                    $resolve($values);
                                }
                            },
                            $reject,
                            $notify
                        );
                }
            }, $reject, $notify);
    }, $cancellationQueue);
}

function reduce($promisesOrValues, callable $reduceFunc, $initialValue = null)
{
    $cancellationQueue = new CancellationQueue();
    $cancellationQueue->enqueue($promisesOrValues);

    return new Promise(function ($resolve, $reject, $notify) use ($promisesOrValues, $reduceFunc, $initialValue, $cancellationQueue) {
        resolve($promisesOrValues)
            ->done(function ($array) use ($reduceFunc, $initialValue, $cancellationQueue, $resolve, $reject, $notify) {
                if (!is_array($array)) {
                    $array = [];
                }

                $total = count($array);
                $i = 0;

                // Wrap the supplied $reduceFunc with one that handles promises and then
                // delegates to the supplied.
                $wrappedReduceFunc = function ($current, $val) use ($reduceFunc, $cancellationQueue, $total, &$i) {
                    $cancellationQueue->enqueue($val);

                    return $current
                        ->then(function ($c) use ($reduceFunc, $total, &$i, $val) {
                            return resolve($val)
                                ->then(function ($value) use ($reduceFunc, $total, &$i, $c) {
                                    return $reduceFunc($c, $value, $i++, $total);
                                });
                        });
                };

                $cancellationQueue->enqueue($initialValue);

                array_reduce($array, $wrappedReduceFunc, resolve($initialValue))
                    ->done($resolve, $reject, $notify);
            }, $reject, $notify);
    }, $cancellationQueue);
}

// Internal functions
function _checkTypehint(callable $callback, $object)
{
    if (!is_object($object)) {
        return true;
    }

    if (is_array($callback)) {
        $callbackReflection = new \ReflectionMethod($callback[0], $callback[1]);
    } elseif (is_object($callback) && !$callback instanceof \Closure) {
        $callbackReflection = new \ReflectionMethod($callback, '__invoke');
    } else {
        $callbackReflection = new \ReflectionFunction($callback);
    }

    $parameters = $callbackReflection->getParameters();

    if (!isset($parameters[0])) {
        return true;
    }

    $expectedException = $parameters[0];

    if (!$expectedException->getClass()) {
        return true;
    }

    return $expectedException->getClass()->isInstance($object);
}

/**
 * Merge dependencies
 * @param ChainDependencyInterface $dep
 * @param ChainDependencyInterface $dep2
 * @param bool $reassign
 */
function _mergeDependencies(&$dep, &$dep2, $reassign = false) {
    if($dep === null || $dep2 === null) return;
    foreach ($dep2 as $key => $value) {
        echo $key . "\n";
        $dep->addDependency($value, $key);
    }
    if($reassign) $dep2 = $dep;
}
