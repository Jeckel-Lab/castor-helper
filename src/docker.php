<?php

/**
 * @author: Julien Mercier-Rojas <julien@jeckel-lab.fr>
 * Created at: 11/01/2024
 */

declare(strict_types=1);

namespace docker;

use Castor\Fingerprint\FileHashStrategy;

use Stringable;

use function Castor\capture;
use function Castor\context;
use function Castor\finder;
use function Castor\fingerprint_exists;
use function Castor\fingerprint_save;
use function Castor\hasher;
use function Castor\io;
use function Castor\run;
use function Castor\wait_for;

/**
 * Watch log of all containers define in docker-compose.yml (or specified container)
 */
function docker_compose_logs(null|string|Stringable $container = null): void
{
    run(
        command: [
            'docker',
            'compose',
            'logs',
            '-f',
            ($container?? '')
        ],
        timeout: 0
    );
}

function docker_container_is_running(string|Stringable $container): bool
{
    return capture(sprintf('docker compose ps | grep Up | grep %s | wc -l', $container)) === '1';
}

function docker_wait_for_healthy(string|Stringable $container, int $timeout = 60): bool
{
    if (! docker_container_is_running($container)) {
        io()->note(sprintf('Starting required container "%s"', $container));
        docker_compose_up(
            container: $container,
            detached: true,
            quiet: true
        );
    }
    wait_for(
        callback: static fn(): bool => capture(
                sprintf("docker compose ps | grep '%s' | grep '(healthy)' | wc -l", $container)
            ) === "1",
        timeout: $timeout,
        message: "Wait for container \"$container\" to be healthy"
    );
    return true;
}

/**
 * Start all docker containers
 * @param bool $detached If true, the containers will be detached from current session
 */
function docker_compose_up(
    null|string|Stringable $container = null,
    bool $detached = false,
    bool $quiet = false,
    array $options = []
): void {
    if (!fingerprint_exists(docker_fingerprint())) {
        docker_compose_build();
    }
    if (null !== $container) {
        $options[] = (string) $container;
    }
    if (! $detached) {
        pcntl_exec(
            capture('which docker'),
            [
                'compose',
                'up',
                ...$options
            ]
        );
        return;
    }

    docker_compose(
        command: [
            'up',
            '-d',
            ...$options
        ],
        timeout: 0,
        quiet: $quiet
    );
}

/**
 * Meta function to run docker compose commands, it mostly check if container needs to be rebuilt before executing a
 * command (updated fingerprint)
 */
function docker_compose(
    array $command,
    float $timeout = 60,
    ?bool $quiet = null
): string
{
    if (!fingerprint_exists(docker_fingerprint())) {
        docker_compose_build();
    }

    $context = context();
    $docker_compose_files = $context['docker-compose-files'] ?? ['-f', 'docker-compose.yml'];

    return run(
        command: [
            'docker',
            'compose',
            ...$docker_compose_files,
            ...$command
        ],
        timeout: $timeout,
        quiet: $quiet
    )->getOutput();
}

/**
 * Build all containers define in docker-compose.yml
 */
function docker_compose_build(): void
{
    run(command: ['docker', 'compose', 'build'], timeout: 0);
    fingerprint_save(docker_fingerprint());
}

function docker_build(string $path, string $tag, array $buildArgs = []): void
{
    io()->info(sprintf('Création de l\'image docker "%s"', $tag));
    run(
        command: [
            'docker',
            'build',
            ...array_map(
                static fn(string $arg, string $value) => '--build-arg='. $arg. '='. $value,
                array_keys($buildArgs),
                array_values($buildArgs)
            ),
            '--tag=' . $tag,
            $path
        ],
        timeout: 0
    );
}

/**
 * Create a fingerprint based on all files required to configure docker containers
 */
function docker_fingerprint(): string
{
    $context = context();
    $hasher = hasher();
    if (is_array($context['docker-fingerprint-files'])) {
        foreach ($context['docker-fingerprint-files'] as $file) {
            $hasher->writeFile($context->currentDirectory . '/' . $file);
        }
    }
    if (is_array($context['docker-fingerprint-directories'])) {
        foreach ($context['docker-fingerprint-directories'] as $directory) {
            $hasher->writeWithFinder(
                finder()
                    ->in($context->currentDirectory . '/' . $directory)
                    ->name('*')
                    ->files(),
                FileHashStrategy::Content
            );
        }
    }
    return $hasher->finish();
}

function compose_bash(string|\Stringable $container, string $shell = 'bash'): void
{
    pcntl_exec(
        capture('which docker'),
        [
            'compose',
            'run',
            '--rm',
            (string) $container,
            $shell
        ]
    );
}

function compose_run_or_exec(string|\Stringable $container, array $command, array $options = [], float $timeout = 60): void
{
    if (docker_container_is_running($container)) {
        // exec
        docker_compose(
            command: [
                'exec',
                ...$options,
                $container,
                ...$command
            ],
            timeout: $timeout
        );
    } else {
        // run
        docker_compose(
            command: [
                'run',
                ...$options,
                $container,
                ...$command
            ],
            timeout: $timeout
        );
    }
}
