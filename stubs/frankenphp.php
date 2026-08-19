<?php

namespace FrankenPHP;

/**
 * The request and response objects the FrankenPHP runtime hands to a worker.
 *
 * They come from the runtime rather than from a package, so nothing in vendor/ declares them
 * and static analysis has nothing to read. Only what this package touches is declared here.
 */
class Request
{
    public function getMethod(): string {}

    public function getUri(): string {}

    /** @return array<string, string> */
    public function getHeaders(): array {}

    public function getBody(): string {}
}

class Response
{
    public function setStatus(int $status): void {}

    public function addHeader(string $name, string $value): void {}

    public function write(string $body): void {}

    public function end(): void {}
}
