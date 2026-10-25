<?php

namespace Omnidocs\Doxygen\Tests;

use Omnidocs\Doxygen\DoxygenSourceFactory;
use Omnidocs\Model\Node;
use Omnidocs\SourceInterface;
use Omnidocs\Testing\RecordingLinker;
use Omnidocs\Testing\SourceContractTests;
use PHPUnit\Framework\TestCase;

/**
 * Real output: TinyXML-2 (leethomason/tinyxml2 10.0.0, Fixtures/tinyxml2/COMMIT) through Doxygen 1.11.0
 * with its own configuration (dox) and GENERATE_XML=YES - the XML as written.
 */
final class DoxygenSourceTest extends TestCase
{
    use SourceContractTests;

    protected function source(): SourceInterface
    {
        return (new DoxygenSourceFactory())->create(['dir' => __DIR__.'/Fixtures/tinyxml2']);
    }

    protected function documentsSymbols(): bool
    {
        return true;
    }

    public function testTheMainPageIsTheHomeAndEachCompoundAPage(): void
    {
        $source = $this->source();
        $paths = array_map(static fn (Node $n): string => $n->path, $source->tree());

        self::assertSame('TinyXML-2', $source->home()?->title);
        self::assertContains('tinyxml2', $paths, 'the namespace');
        self::assertContains('tinyxml2-xmldocument', $paths, 'a class');
        self::assertContains('tinyxml2-h', $paths, 'a file');
        self::assertNotContains('std', $paths);
    }

    public function testAMembersDeclarationTextAndParametersAreItsSection(): void
    {
        $links = new RecordingLinker();
        $html = (string) $this->source()->page('tinyxml2-xmldocument', $links)?->html();

        self::assertMatchesRegularExpression('#<h2 id="classtinyxml2_1_1_x_m_l_document_1a[0-9a-f]+"><code>Parse</code> <span class="api-kind">function</span></h2>#', $html);
        self::assertStringContainsString('XMLError tinyxml2::XMLDocument::Parse(const char *xml, size_t nBytes=static_cast&lt; size_t &gt;(-1))', $html);
        self::assertStringContainsString('href="repo:tinyxml2.h#L', $html, 'its line in the repository');
        self::assertStringNotContainsString('<code>_charBuffer</code>', $html, 'a private member is not shown');
        $parse = null;
        foreach ($this->source()->symbols() as $symbol) {
            $parse = 'tinyxml2::XMLDocument::Parse' === $symbol->name ? $symbol : $parse;
        }
        self::assertNotNull($parse);
        self::assertSame('tinyxml2-xmldocument', $parse->page);
        self::assertSame('function', $parse->kind);
    }
}
