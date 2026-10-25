<?php

namespace Omnidocs\Doxygen;

use Omnidocs\Config;
use Omnidocs\Html\Conventions;
use Omnidocs\SourceFactory;
use Omnidocs\SourceInterface;

/**
 * The "doxygen" source:
 *
 *   dir     Doxygen's XML output (GENERATE_XML=YES, XML_OUTPUT)           required
 *   source  what Doxygen's file paths are relative to, in the repository   ""
 *   title   the home's title when the project has no main page             "Reference"
 *   labels  the callouts' words
 */
final class DoxygenSourceFactory extends SourceFactory
{
    public function getName(): string
    {
        return 'doxygen';
    }

    protected function populate(Config $config): void
    {
        $config->defaults(['source' => '', 'title' => 'Reference', 'labels' => [], 'omnidocs.required_options' => ['dir']]);
    }

    protected function build(Config $config): SourceInterface
    {
        return new DoxygenSource($config->directory('dir', $this->getName()), (string) $config->string('source'), (string) $config->string('title'), new Conventions($config->map('labels')));
    }
}
