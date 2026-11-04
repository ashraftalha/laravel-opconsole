<?php

namespace AshrafTalha\OpConsole\Tests\Support;

use AshrafTalha\OpConsole\Support\Runtime;

/** A Runtime whose every probe is a public property, so tests can describe any Octane setup. */
class FakeRuntime extends Runtime
{
    public bool $installed = true;

    public bool $serving = false;

    public ?bool $running = false;

    /** @var array<string, string> */
    public array $extensions = ['swoole' => '6.0.2'];

    /** @var array<string, string> */
    public array $ini = ['memory_limit' => '512M', 'post_max_size' => '8M', 'upload_max_filesize' => '2M', 'opcache.enable_cli' => '1'];

    /** @var list<string> */
    public array $files = [];

    public ?int $cpus = 4;

    public bool $writable = true;

    public function octaneInstalled(): bool
    {
        return $this->installed;
    }

    public function servingViaOctane(): bool
    {
        return $this->serving;
    }

    public function extensionLoaded(string $name): bool
    {
        return array_key_exists($name, $this->extensions);
    }

    public function extensionVersion(string $name): ?string
    {
        return $this->extensions[$name] ?? null;
    }

    public function fileExists(string $path): bool
    {
        return in_array($path, $this->files, true);
    }

    public function isWritable(string $path): bool
    {
        return $this->writable;
    }

    public function ini(string $key): string
    {
        return $this->ini[$key] ?? '';
    }

    public function cpuCount(): ?int
    {
        return $this->cpus;
    }

    public function octaneServerRunning(): ?bool
    {
        return $this->running;
    }
}
