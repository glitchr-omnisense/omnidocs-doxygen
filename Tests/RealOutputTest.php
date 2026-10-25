<?php

namespace Omnidocs\Doxygen\Tests;

use Omnidocs\Doxygen\DoxygenSourceFactory;
use Omnidocs\SourceInterface;
use Omnidocs\Testing\SourceContractTests;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The conformance suite on the tool's whole output, as the harness writes it (docker compose run --rm doxygen,
 * then docker compose run --rm omnidocs real): OMNIDOCS_DOXYGEN_REAL names it; skipped without it.
 */
#[Group('real')]
final class RealOutputTest extends TestCase
{
    use SourceContractTests;

    protected function setUp(): void
    {
        $real = getenv('OMNIDOCS_DOXYGEN_REAL');
        if (false === $real || !file_exists($real)) {
            self::markTestSkipped('OMNIDOCS_DOXYGEN_REAL names no output of the tool.');
        }
    }

    protected function source(): SourceInterface
    {
        return (new DoxygenSourceFactory())->create(['dir' => (string) getenv('OMNIDOCS_DOXYGEN_REAL')]);
    }

    protected function documentsSymbols(): bool
    {
        return true;
    }
}
