<?php

namespace Omnidocs\Doxygen;

use Omnidocs\Exception\SourceException;
use Omnidocs\Html\Conventions;
use Omnidocs\Html\Headings;
use Omnidocs\Html\Text;
use Omnidocs\LinkerInterface;
use Omnidocs\Model\Node;
use Omnidocs\Model\Page;
use Omnidocs\Model\Rendered;
use Omnidocs\Model\Symbol;
use Omnidocs\SourceInterface;
use Omnidocs\Support\Path;

/**
 * A C or C++ project's reference from Doxygen's XML output (GENERATE_XML=YES):
 * its main page as the home, then a page per namespace, class, struct, union
 * and file, each with its description and its public members - their
 * declaration, their text, their parameters and what they return - at the
 * anchors Doxygen gives them; every compound and member an API entry.
 *
 * Doxygen's markup becomes omnidocs' conventions: \note, \warning, \see,
 * \since, \deprecated are callouts, a code example a frame, a \ref a link to
 * its page and anchor, a file and line a link to the repository.
 *
 *   new DoxygenSource('/build/xml', source: '');
 */
final class DoxygenSource implements SourceInterface
{
    private const PAGES = ['namespace', 'class', 'struct', 'union', 'interface', 'file'];

    private const SECTIONS = ['public-type', 'public-func', 'public-static-func', 'public-attrib', 'public-static-attrib', 'func', 'var', 'typedef', 'enum', 'define', 'user-defined'];

    /** @var array<string, array{kind: string, name: string, path: string}>|null compound id => what it is */
    private ?array $compounds = null;

    /** @var array<string, string>|null member id => its compound's id */
    private ?array $members = null;

    /** @var array<string, \DOMDocument> */
    private array $documents = [];

    private readonly Conventions $conventions;

    /**
     * @param string $dir    Doxygen's XML output folder (index.xml and a file per compound)
     * @param string $source what the paths Doxygen read are relative to, inside the repository ("" its root)
     */
    public function __construct(
        private readonly string $dir,
        private readonly string $source = '',
        private readonly string $title = 'Reference',
        ?Conventions $conventions = null,
    ) {
        $this->conventions = $conventions ?? new Conventions();
    }

    public function home(): ?Node
    {
        return new Node('', $this->homeTitle(), null, 0);
    }

    public function tree(): array
    {
        $tree = [];
        $i = 0;
        foreach ($this->compounds() as $compound) {
            $tree[] = new Node($compound['path'], $compound['name'], null, ++$i);
        }

        return $tree;
    }

    public function page(string $path, ?LinkerInterface $links = null): ?Page
    {
        if ('' === $path) {
            $html = $this->homeHtml($links);

            return new Page('', $this->homeTitle(), new Rendered($html, $this->homeTitle(), Headings::of($html, 2, 3), [], Text::of($html)));
        }
        foreach ($this->compounds() as $id => $compound) {
            if ($compound['path'] === $path) {
                $html = $this->compoundHtml($id, $links);

                return new Page($path, $compound['name'], new Rendered($html, $compound['name'], Headings::of($html, 2, 3), [], Text::of($html)), null, array_values(array_filter($this->symbols(), static fn (Symbol $s): bool => $s->page === $path)));
            }
        }

        return null;
    }

    public function symbols(): array
    {
        $symbols = [];
        foreach ($this->compounds() as $id => $compound) {
            $definition = $this->compoundDef($id);
            $symbols[] = new Symbol($compound['kind'], $compound['name'], $compound['path'], null, self::plain($this->first($definition, 'briefdescription')));
            foreach ($this->publicMembers($definition) as $member) {
                $name = self::text($this->first($member, 'name'));
                $qualified = self::text($this->first($member, 'qualifiedname')) ?: ($compound['name'].'::'.$name);
                $symbols[] = new Symbol((string) $member->getAttribute('kind'), $qualified, $compound['path'], (string) $member->getAttribute('id'), self::plain($this->first($member, 'briefdescription')), self::signature($member), $compound['name']);
            }
        }

        return $symbols;
    }

    /** @return array<string, array{kind: string, name: string, path: string}> */
    private function compounds(): array
    {
        if (null !== $this->compounds) {
            return $this->compounds;
        }
        $index = $this->load('index');
        $compounds = [];
        $members = [];
        $taken = [];
        $byKind = [];
        foreach ($index->getElementsByTagName('compound') as $compound) {
            if (!$compound instanceof \DOMElement) {
                continue;
            }
            $id = $compound->getAttribute('refid');
            foreach ($compound->getElementsByTagName('member') as $member) {
                if ($member instanceof \DOMElement) {
                    $members[$member->getAttribute('refid')] ??= $id;
                }
            }
            $kind = $compound->getAttribute('kind');
            if (!\in_array($kind, self::PAGES, true) || 'std' === self::text($this->first($compound, 'name'))) {
                continue;
            }
            $byKind[array_search($kind, self::PAGES, true)][] = [$id, $kind, self::text($this->first($compound, 'name'))];
        }
        ksort($byKind);
        foreach ($byKind as $list) {
            usort($list, static fn (array $a, array $b): int => strcmp($a[2], $b[2]));
            foreach ($list as [$id, $kind, $name]) {
                $compounds[$id] = ['kind' => $kind, 'name' => $name, 'path' => Headings::id(Path::slug(str_replace(['::', '.'], '-', $name)), $taken)];
            }
        }
        $this->members = $members;

        return $this->compounds = $compounds;
    }

    private function homeTitle(): string
    {
        $page = is_file($this->dir.'/indexpage.xml') ? $this->load('indexpage') : null;
        $title = null !== $page ? self::text($this->first($this->first($page->documentElement, 'compounddef'), 'title')) : '';

        return '' !== $title ? $title : $this->title;
    }

    private function homeHtml(?LinkerInterface $links): string
    {
        $html = '';
        if (is_file($this->dir.'/indexpage.xml')) {
            $page = $this->load('indexpage');
            $definition = $this->first($page->documentElement, 'compounddef');
            $html .= $this->description($this->first($definition, 'briefdescription'), $links).$this->description($this->first($definition, 'detaileddescription'), $links);
        }
        $html .= '<ul class="api-index">';
        foreach ($this->compounds() as $id => $compound) {
            $html .= '<li><a href="'.Conventions::escape(null !== $links ? $links->page($compound['path']) : '#').'"><code>'.Conventions::escape($compound['name']).'</code></a> <span class="api-kind">'.Conventions::escape($compound['kind']).'</span>'
                .(($brief = self::plain($this->first($this->compoundDef($id), 'briefdescription'))) !== '' ? ' - '.Conventions::escape($brief) : '').'</li>';
        }

        return $html.'</ul>';
    }

    private function compoundHtml(string $id, ?LinkerInterface $links): string
    {
        $definition = $this->compoundDef($id);
        $html = '';
        $location = $this->first($definition, 'location');
        if (null !== $location && null !== $links && null !== ($url = $this->locate($location, $links))) {
            $html .= '<p class="api-declaration"><a class="api-source" href="'.Conventions::escape($url).'">'.Conventions::escape(basename($location->getAttribute('file'))).($location->getAttribute('line') ? ':'.$location->getAttribute('line') : '').'</a></p>';
        }
        $bases = [];
        foreach ($definition->getElementsByTagName('basecompoundref') as $base) {
            $bases[] = '<code>'.Conventions::escape(trim($base->textContent)).'</code>';
        }
        if ([] !== $bases) {
            $html .= '<p class="api-bases">: '.implode(', ', $bases).'</p>';
        }
        $html .= $this->description($this->first($definition, 'briefdescription'), $links).$this->description($this->first($definition, 'detaileddescription'), $links);
        foreach ($this->publicMembers($definition) as $member) {
            $anchor = (string) $member->getAttribute('id');
            $html .= '<h2 id="'.Conventions::escape($anchor).'"><code>'.Conventions::escape(self::text($this->first($member, 'name'))).'</code> <span class="api-kind">'.Conventions::escape((string) $member->getAttribute('kind')).'</span></h2>';
            $html .= $this->conventions->code(Conventions::escape(self::signature($member)), 'cpp');
            $memberLocation = $this->first($member, 'location');
            if (null !== $memberLocation && null !== $links && null !== ($url = $this->locate($memberLocation, $links))) {
                $html .= '<p class="api-declaration"><a class="api-source" href="'.Conventions::escape($url).'">'.Conventions::escape(basename($memberLocation->getAttribute('file'))).':'.$memberLocation->getAttribute('line').'</a></p>';
            }
            $html .= $this->description($this->first($member, 'briefdescription'), $links).$this->description($this->first($member, 'detaileddescription'), $links);
            $values = '';
            foreach ($member->getElementsByTagName('enumvalue') as $value) {
                if ($value instanceof \DOMElement) {
                    $values .= '<li><code>'.Conventions::escape(self::text($this->first($value, 'name'))).'</code> '.$this->description($this->first($value, 'briefdescription'), $links).'</li>';
                }
            }
            if ('' !== $values) {
                $html .= '<ul class="api-values">'.$values.'</ul>';
            }
        }

        return $html;
    }

    /** @return list<\DOMElement> */
    private function publicMembers(?\DOMElement $definition): array
    {
        $members = [];
        if (null === $definition) {
            return [];
        }
        foreach ($definition->getElementsByTagName('sectiondef') as $section) {
            if (!$section instanceof \DOMElement || !\in_array($section->getAttribute('kind'), self::SECTIONS, true)) {
                continue;
            }
            foreach ($section->childNodes as $member) {
                if ($member instanceof \DOMElement && 'memberdef' === $member->nodeName && 'private' !== $member->getAttribute('prot') && 'protected' !== $member->getAttribute('prot')) {
                    $members[] = $member;
                }
            }
        }

        return $members;
    }

    /** Doxygen's description markup as HTML (omnidocs' conventions). */
    private function description(?\DOMElement $element, ?LinkerInterface $links): string
    {
        return null === $element ? '' : $this->children($element, $links);
    }

    private function children(\DOMNode $node, ?LinkerInterface $links): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $this->convert($child, $links);
        }

        return $html;
    }

    private function convert(\DOMNode $node, ?LinkerInterface $links): string
    {
        if ($node instanceof \DOMText) {
            return Conventions::escape($node->textContent);
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }
        $inner = fn (): string => $this->children($node, $links);

        return match ($node->nodeName) {
            'para' => $this->para($node, $links),
            'computeroutput' => '<code>'.$inner().'</code>',
            'emphasis' => '<em>'.$inner().'</em>',
            'bold' => '<strong>'.$inner().'</strong>',
            'linebreak' => '<br>',
            'itemizedlist' => '<ul>'.$inner().'</ul>',
            'orderedlist' => '<ol>'.$inner().'</ol>',
            'listitem' => '<li>'.$inner().'</li>',
            'ulink' => '<a href="'.Conventions::escape($node->getAttribute('url')).'" rel="noopener">'.$inner().'</a>',
            'ref' => $this->ref($node, $links, $inner()),
            'programlisting' => $this->conventions->code(Conventions::escape(rtrim(implode("\n", array_map(static fn (\DOMNode $line): string => self::codeline($line), iterator_to_array($node->getElementsByTagName('codeline')))))), ltrim($node->getAttribute('filename'), '.') ?: 'cpp'),
            'verbatim', 'preformatted' => '<pre>'.Conventions::escape($node->textContent).'</pre>',
            'simplesect' => $this->simplesect($node, $links),
            'parameterlist' => $this->parameters($node, $links),
            'heading' => '<h3>'.$inner().'</h3>',
            'sp' => ' ',
            'anchor', 'image', 'xrefsect', 'indexentry' => '',
            'table' => '<table>'.$inner().'</table>',
            'row' => '<tr>'.$inner().'</tr>',
            'entry' => ('true' === $node->getAttribute('thead') ? '<th>' : '<td>').$inner().('true' === $node->getAttribute('thead') ? '</th>' : '</td>'),
            default => $inner(),
        };
    }

    /** A paragraph: its callouts and code blocks, which HTML does not allow in a <p>, stand on their own. */
    private function para(\DOMElement $node, ?LinkerInterface $links): string
    {
        $html = '';
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && \in_array($child->nodeName, ['simplesect', 'parameterlist', 'programlisting', 'itemizedlist', 'orderedlist', 'verbatim', 'table', 'heading'], true)) {
                $html .= '' !== trim(strip_tags($text)) ? '<p>'.trim($text).'</p>' : '';
                $text = '';
                $html .= $this->convert($child, $links);
            } else {
                $text .= $this->convert($child, $links);
            }
        }

        return $html.('' !== trim(strip_tags($text, '<code><a><img>')) ? '<p>'.trim($text).'</p>' : '');
    }

    private function simplesect(\DOMElement $node, ?LinkerInterface $links): string
    {
        $inner = $this->children($node, $links);
        $kind = $node->getAttribute('kind');

        return match ($kind) {
            'note', 'remark' => $this->conventions->callout('note', $inner),
            'warning', 'attention' => $this->conventions->callout('warning', $inner),
            'see' => $this->conventions->callout('seealso', $inner),
            'since' => $this->conventions->callout('versionadded', $inner, preg_match('/(\d+(?:\.\d+)+)/', Text::of($inner), $v) ? $v[1] : null),
            'deprecated' => $this->conventions->callout('deprecated', $inner),
            'return' => '<dl class="api-params"><dt>Returns</dt><dd>'.$inner.'</dd></dl>',
            default => '<p class="api-'.Conventions::escape($kind).'">'.$inner.'</p>',
        };
    }

    private function parameters(\DOMElement $node, ?LinkerInterface $links): string
    {
        $rows = '';
        foreach ($node->getElementsByTagName('parameteritem') as $item) {
            if (!$item instanceof \DOMElement) {
                continue;
            }
            $names = [];
            foreach ($item->getElementsByTagName('parametername') as $name) {
                $names[] = '<code>'.Conventions::escape(trim($name->textContent)).'</code>';
            }
            $rows .= '<dt>'.implode(', ', $names).'</dt><dd>'.$this->description($this->first($item, 'parameterdescription'), $links).'</dd>';
        }

        return '' !== $rows ? '<dl class="api-params">'.$rows.'</dl>' : '';
    }

    private function ref(\DOMElement $node, ?LinkerInterface $links, string $inner): string
    {
        if (null === $links) {
            return $inner;
        }
        $refid = $node->getAttribute('refid');
        $compounds = $this->compounds();
        $compound = $compounds[$refid] ?? (isset($this->members[$refid]) ? ($compounds[$this->members[$refid]] ?? null) : null);
        if (null === $compound) {
            return $inner;
        }
        $anchor = isset($compounds[$refid]) ? null : $refid;
        // A member that is not public has no anchor in its page: its page alone.
        if (null !== $anchor) {
            $public = false;
            foreach ($this->publicMembers($this->compoundDef((string) $this->members[$refid])) as $member) {
                $public = $public || $member->getAttribute('id') === $refid;
            }
            $anchor = $public ? $anchor : null;
        }

        return '<a href="'.Conventions::escape($links->page($compound['path'], $anchor)).'">'.$inner.'</a>';
    }

    private function locate(\DOMElement $location, LinkerInterface $links): ?string
    {
        $file = $location->getAttribute('file');
        $path = Path::normalise(('' !== $this->source ? trim($this->source, '/').'/' : '').ltrim($file, '/'));

        return null !== $path && '' !== $file ? $links->file($path, $location->getAttribute('line') ? 'L'.$location->getAttribute('line') : null) : null;
    }

    private function compoundDef(string $id): ?\DOMElement
    {
        $document = $this->load($id);

        return $this->first($document->documentElement, 'compounddef');
    }

    private function load(string $name): \DOMDocument
    {
        if (isset($this->documents[$name])) {
            return $this->documents[$name];
        }
        $file = $this->dir.'/'.$name.'.xml';
        if (!is_file($file)) {
            throw new SourceException(\sprintf('No %s.xml in %s: not Doxygen\'s XML output.', $name, $this->dir));
        }
        $document = new \DOMDocument();
        if (!@$document->load($file, \LIBXML_NONET | \LIBXML_NOCDATA)) {
            throw new SourceException(\sprintf('%s does not parse as XML.', $file));
        }

        return $this->documents[$name] = $document;
    }

    private function first(?\DOMNode $parent, string $name): ?\DOMElement
    {
        if (null === $parent) {
            return null;
        }
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->nodeName === $name) {
                return $child;
            }
        }

        return null;
    }

    private static function signature(\DOMElement $member): string
    {
        $definition = '';
        $args = '';
        foreach ($member->childNodes as $child) {
            if ($child instanceof \DOMElement && 'definition' === $child->nodeName) {
                $definition = trim($child->textContent);
            } elseif ($child instanceof \DOMElement && 'argsstring' === $child->nodeName) {
                $args = trim($child->textContent);
            }
        }

        return trim($definition.$args);
    }

    private static function codeline(\DOMNode $line): string
    {
        $text = '';
        foreach ((new \DOMXPath($line->ownerDocument ?? new \DOMDocument()))->query('.//text()|.//sp', $line) ?: [] as $part) {
            $text .= 'sp' === $part->nodeName ? ' ' : $part->textContent;
        }

        return $text;
    }

    private static function text(?\DOMNode $node): string
    {
        return null === $node ? '' : trim((string) preg_replace('/\s+/', ' ', $node->textContent));
    }

    private static function plain(?\DOMNode $node): string
    {
        return self::text($node);
    }
}
