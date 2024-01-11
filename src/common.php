<?php

/**
 * @author: Julien Mercier-Rojas <julien@jeckel-lab.fr>
 * Created at: 11/01/2024
 */

declare(strict_types=1);

/**
 * Wait for a function to be true
 * @param callable():bool $callback
 */
function wait_for(callable $callback, int $timeout = 10, int $interval = 1): bool
{
    $start = time();
    while (!$callback()) {
        if (time() - $start > $timeout) {
            return false;
        }
        sleep($interval);
    }
    return true;
}
