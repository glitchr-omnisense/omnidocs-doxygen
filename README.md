# omnidocs/doxygen

A C or C++ project's reference from Doxygen's XML output (`GENERATE_XML=YES`), as an
[omnidocs](https://github.com/glitchr-studio/omnidocs) manual: its main page as the home, then a page
per namespace, class, struct, union and file - its description, its bases, its public members with
their declaration, text, parameters and return, enum values - at the anchors Doxygen gives them; every
compound and public member an API entry. \note, \warning, \see, \since, \deprecated are callouts, a
code example a frame, a \ref a link to its page and anchor, a declaration's file and line a link to
the repository.

```php
$reference = (new Omnidocs\Doxygen\DoxygenSourceFactory())->create(['dir' => 'build/xml']);
```

| Option | Default | Meaning |
|---|---|---|
| `dir` | (required) | Doxygen's XML output |
| `source` | `""` | what Doxygen's file paths are relative to, in the repository |
| `title` | `Reference` | the home's title when the project has no main page |
| `labels` | English | the callouts' words |

## Verified, and not

- Verified (omnidocs' harness, PHP 8.4): omnidocs' conformance suite on TinyXML-2 (leethomason/tinyxml2
  10.0.0) through Doxygen 1.11.0 with its own configuration - its whole XML output as the fixture: the
  main page, 24 compounds, 407 entries; `BareTest`.
- Not verified: Doxygen's groups (\defgroup) and related pages beyond the main page; C#, Java and Python
  projects; diagrams (Doxygen draws them as images, not in the XML).

Licence: MIT.
