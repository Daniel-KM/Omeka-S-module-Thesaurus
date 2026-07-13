Thesaurus (module for Omeka S)
==============================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Thesaurus] is a module for [Omeka S] that allows to manage a standard thesaurus
(ontology [skos]) to describe documents with a tree structure (_arborescence_)
or a filing plan (_plan de classement_):

- the skos ontology is included;
- two resource templates are included to set the thesaurus scheme and each
  concept to allow to build the thesaurus as a list of items;
- an admin view to manage the tree structure;
- an import tool to build a thesaurus from a standard SKOS file (Unesco,
  OpenTheso…) or a structured text file, by upload or by url;
- a view helper to display the tree of concepts in the theme, or part of it;
- a site block to display the thesaurus as a tree, or part of it.

The view helper can be used for any purpose, for example to build a hierarchical
list of item sets, but this is not the main purpose.

The thesaurus can be used to fill resources via a dedicated data type, without
the module [Custom Vocab] (kept for compatibility).

A future version may rely on ISO 25964 (Thesauri and interoperability with
other vocabularies).


Installation
------------

See general end user documentation for [installing a module].

This module requires the module [Common], that should be installed first.

An optional module is [Custom Vocab].

* From the zip

Download the last release [Thesaurus.zip] from the list of releases, and
uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `Thesaurus`.

Then install it like any other Omeka module and follow the config instructions.

* For test

The module includes a comprehensive test suite with unit and functional tests.
Run them from the root of Omeka:

```sh
vendor/bin/phpunit -c modules/Thesaurus/phpunit.xml --testdox
```


Usage
-----

This module allows to manage concept as any other items and to use and to manage
directly the terms of the ontology [skos].

You can create a thesaurus in various ways.

### Import a thesaurus (by file or url)

The page "Thesaurus" in the admin has an "Import" button (route
`/admin/thesaurus/convert`) that builds a thesaurus from a standard SKOS file or
a structured text file.

#### Source

The source may be:

- an uploaded file;
- a remote url, for example an [OpenTheso] export (see below) or any published
  SKOS file. The file is fetched by the server, so the server must be able to
  reach it.

Gzipped files (`.gz`, for example a [GEMET] export) are decompressed
automatically when the php extension `zlib` is available. The maximum size of an
uploaded file depends on the php settings `upload_max_filesize` and `post_max_size`;
increase them to import a big thesaurus.

All labels are normalized to Unicode NFC on import, so identical-looking labels
are stored identically (no duplicates, reliable matching and truncation).

#### Destination

- Preview: display the flat list, to check it or to copy-paste it into a custom
  vocab. From the same screen, the list can also be downloaded, or imported as a
  custom vocab or a thesaurus (what you see is what is imported).
- Custom vocabulary: create a custom vocab of type "terms". The terms can be the
  full path ("Europe :: France :: Paris"), the indented label, or the leaf label
  only.
- Thesaurus: create the item set (skos:Collection), the scheme (`skos:ConceptScheme`)
  and one item by concept (skos:Concept) with the broader/narrower relations and
  the positions. Optionally, the linked custom vocab (type "item set") can be
  created too.

The properties used to fill the concepts (descriptor, path, ascendance) and the
ascendance separator are set in the main settings of Omeka (tab "Thesaurus").

#### Standard SKOS

A standard SKOS file in RDF/XML, Turtle, JSON-LD or N-Triples, for example the
[thesaurus of Unesco] or a thesaurus managed with [OpenTheso].

For OpenTheso, the url of the rest api is built as `https://{host}/opentheso/openapi/v1/thesaurus/{idTheso}`.
The list of the thesaurus of an instance (with their `idTheso`) is available at
`https://{host}/opentheso/openapi/v1/thesaurus`.
For example, the thesaurus "Pactols Lieux" of Frantiq is `https://pactols.frantiq.fr/opentheso/openapi/v1/thesaurus/th17`.

All the values of the concepts are imported. The preferred labels and the
hierarchy (broader/narrower) are always imported; a set of checkboxes lets you
choose the other values to import:

- **Multilingual**: keep the languages of the values; else all the values are
  set to the Omeka admin language (normalized, so `fr_FR` matches the rdf tag
  `fr`).
- **Documentation**: definition, scope note, note, example, history/editorial/
  change notes.
- **Notation**.
- **Internal relations**: `skos:related`, imported as linked resources (a
  second pass links them once all the concepts exist).
- **External alignments**: `skos:exactMatch`, `closeMatch`, `broadMatch`,
  `narrowMatch`, `relatedMatch`, imported as uris.
- **Other vocabularies**: any other property (dcterms, etc.) is imported when it
  exists in Omeka (matched by uri, since the rdf prefixes may differ), else it is
  reported in the logs. Unchecked by default.

The preview only shows the flat list of preferred labels, not all the imported
values.

### Manual creation of a thesaurus

Create first an item set with class "skos:Collection" or "skos:orderedCollection".
This is required to get the display the tree structure in resource form via Custom Vocab.
This item set must contains only the scheme and all concepts.

Then create the scheme with the template "Thesaurus Scheme", then each concept
with the template "Thesaurus Concept". Each concept should have the required property
"skos:inScheme" filled with the scheme.

To make the structure, you can link each concept via the resource form interface,
or via the menu "Thesaurus".

### Use of concepts as related items

You can create your own thesaurus (or import it via module such [Bulk Import]).
For that, use the integrated ontology `skos`, that contains the classes and the
properties to manage items as concepts.

### Input file formats

In addition to the standard SKOS above, the import tool supports structured text
formats. They can be created easily with a text editor or [LibreOffice] Calc.

#### Hierarchical text with tabulation offset

In this mode, the tabulations indicate the hierarchy (see example for [countries](data/examples/countries.txt)):

```
Europe
       France
               Paris
       United Kingdom
               England
                       London
Asia
       Japan
               Tokyo
```

#### Hierarchical text with tabulation offset with code prepended/appended

This is a variant of previous mode, that allows to import metadata about each
descriptors. You can use any code or even property terms. UF (Used for), CC
(classification code), and SN (Scope Note) are commonly used in thesaurus.
Codes are case sensitive. Take care not to use real words used in the thesaurus.

```
Europe
UF Europa
CC EU
       France
       CC FRA
       United Kingdom
       UF United Kingdom of Great Britain and Northern Ireland
       CC UK
               England
               UF ENG
```

#### Structure with label

In this mode, the first column is the structure and the second, after spaces, is
the label (see example for [countries](data/examples/countries.txt)):

```
01          Europe
01-01       France
01-01-01    Paris
01-02       United Kingdom
01-02-01    England
01-02-01-01 London
02          Asia
02-01       Japan
02-01-01    Tokyo
```

The output of previous formats will be a flat thesaurus that you can copy-paste
as a custom vocab or process directly to create a thesaurus with skos relations:

```
Europe
Europe :: France
Europe :: France :: Paris
Europe :: United Kingdom
Europe :: United Kingdom :: England
Europe :: United Kingdom :: England :: London
Asia
Asia :: Japan
Asia :: Japan :: Tokyo
```

### Fill a resource with a thesaurus concept

A data type is registered for each thesaurus, so a property of a resource
template can be filled with a concept selected in the tree, without the module
[Custom Vocab] and without an item set.

In a resource template, add the data type "Thesaurus: {scheme label}" to a
property. The selected concept is stored as a linked resource (the concept
item), so it can be searched (see below) and displayed as a link.

Like the modules [Custom Vocab] and [Table], the thesaurus data types are also
available as **value annotation** and in **[CSV Import]** (as resources).

The custom vocab (type "item set") still works and is kept for compatibility.
To add the thesaurus data type to the templates that currently use the custom
vocab of a thesaurus, run the task "Thesaurus: Add thesaurus data type to custom
vocab templates" via the module [Easy Admin] (Check and fix), or the button
"Migrate data types" of the thesaurus page: the thesaurus data type is added in
first position (default), the custom vocab is kept and the existing values
remain valid. The configs of the module [Advanced Resource Template] are
migrated too.

### Page blocks

A site block "Thesaurus" is available to include the thesaurus on any page, or a
part of it (branch, narrowers, ascendants, descendants, etc.).


French translation
------------------

The French translation of SKOS conforms to the [official translation].


Development
-----------

### Service Thesaurus\Thesaurus, controller plugin and view helper `thesaurus()`

When possible, use the service Thesaurus\Thesaurus. The controller plugin and
the view helper are wrapper to it.

The controller plugin or view helper thesaurus() allows to create a thesaurus
and to get the whole tree, a flat tree, the tops, any branch, the ascendants,
the descendants, the siblings, etc. It can be used in the theme or anywhere
else.

To build a thesaurus, use `$thesaurus = $this->thesaurus($item)`, where item is
the scheme, the item set or any concept. Then, you can get any data for it with
the methods.

To get data for any other concept of the thesaurus, set it first. For example,
to get the  ascendants for a concept, use `$ascendants = $thesaurus->setItem($item)->ascendants()`.

The view helper has the specific method `$this->thesaurus($item)->display()` to
display the tree, a list, or anything else via a view template.

### Html "select" with the tree

A specific form element and the associated view helper `ThesaurusSelect` allow
to create a html `<select>` with options. This is the recommended way to create
select.

### Api to search a resource with a concept

- It is possible to do a query according to thesaurus items. In fact, a standard
  query on properties with type `res` is the simplest way to do it.

- You may use the format `thesaurus[dcterms:subject][]=xxx`, where xxx is the
  item id of an item of the thesaurus.

### Api to search a resource belonging to a thesaurus

To search resources with a property value belonging to a thesaurus, you may use
the query property with the type `cat` and the text the thesaurus item id:
`property[0][property]=dcterms:subject&property[0][type]=cat&property[0][text]=xxx`,
where xxx is the thesaurus item id.

### Api sort

It is possible to sort a query according to thesaurus items order with `sort_by=thesaurus&sort_thesaurus=xxx`,
where xxx is the item id of the thesaurus.

### Use with the module Collecting

The module [Collecting] can collect a concept of a thesaurus directly. The
simplest way is a prompt with the input type "Custom vocab" pointing to the
custom vocab of the thesaurus.

The old [fork of the module Collecting] is no more needed.

A prompt with the input type "Item resource" and a resource query can be used
too, in order to limit and to sort the proposed concepts. Choose a property to
fill, the input type "Item resource", then the query:
`resource_class_id[0]=xxx&property[0][joiner]=and&property[0][property]=skos:inScheme&property[0][type]=res&property[0][text]=yyy&sort_by=thesaurus&sort_thesaurus=yyy`.
or in php:

```php
    'resource_class_id' => [
        xxx,
    ],
    'property' => [
        [
            'joiner' => 'and',
            'property' => 'skos:inScheme',
            'type' => 'res',
            'text' => 'yyy',
        ],
    ],
    'sort_by' => 'thesaurus',
    'sort_thesaurus' => 'zzz',
```

Here, `xxx` is the resource class id of `skos:ConceptScheme` and `yyy` is the
item id of the scheme, as string.


TODO
----

* [-] Manage terms as a full resources, separately from items (like Annotation)? No.
* [ ] Manage representation when a term belongs to multiple thesaurus? Probably useless with association.
* [ ] Implement a tree iterator in representation, plugin and helper.
* [ ] Uninstall vocabulary and resources templates if not used.
* [ ] Create a data type to store the ascendance or the full path with resource ids and display with multiple links.
* [ ] Update ascendance of descendants with a single job after batch edit.
* [ ] Remove the process with pref label / alt label to build select. Add an intermediate process.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitLab.


License
-------

This module is published under the [CeCILL v2.1] license, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

In consideration of access to the source code and the rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors only have limited liability.

In this respect, the risks associated with loading, using, modifying and/or
developing or reproducing the software by the user are brought to the user’s
attention, given its Free Software status, which may make it complicated to use,
with the result that its use is reserved for developers and experienced
professionals having in-depth computer knowledge. Users are therefore encouraged
to load and test the suitability of the software as regards their requirements
in conditions enabling the security of their systems and/or data to be ensured
and, more generally, to use and operate it in the same conditions of security.
This Agreement may be freely reproduced and published, provided it is not
altered, and that no provisions are either added or removed herefrom.


Copyright
---------

* Copyright Daniel Berthereau, 2018-2026 (see [Daniel-KM] on GitLab)

First version of this module was developed for the project [Ontologie du christianisme médiéval en images]
for the [Institut national d’histoire de l’art] (INHA). Improvements were done
for various projects, in particular for the backend of the database [ConsiliaWeb]
of the French higher administrative court [Conseil d’État].


[Omeka S]: https://omeka.org/s
[Thesaurus]: https://gitlab.com/Daniel-KM/Omeka-S-module-Thesaurus
[skos]: https://www.w3.org/2004/02/skos
[installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Thesaurus.zip]: https://gitlab.com/Daniel-KM/Omeka-S-module-Thesaurus/-/releases
[Common]: https://gitlab.com/Daniel-KM/Omeka-S-module-Common
[Bulk Import]: https://gitlab.com/Daniel-KM/Omeka-S-module-BulkImport
[Custom Vocab]: https://github.com/omeka-s-modules/CustomVocab
[Easy Admin]: https://gitlab.com/Daniel-KM/Omeka-S-module-EasyAdmin
[Table]: https://gitlab.com/Daniel-KM/Omeka-S-module-Table
[CSV Import]: https://github.com/omeka-s-modules/CSVImport
[Advanced Resource Template]: https://gitlab.com/Daniel-KM/Omeka-S-module-AdvancedResourceTemplate
[Value Suggest]: https://github.com/omeka-s-modules/ValueSuggest
[Value Suggest: Any]: https://gitlab.com/Daniel-KM/Omeka-S-module-ValueSuggestAny
[official translation]: https://www.sparna.fr/skos/SKOS-traduction-francais.html
[LibreOffice]: https://libreoffice.org
[thesaurus of Unesco]: https://vocabularies.unesco.org/browser/thesaurus/
[OpenTheso]: https://opentheso.hypotheses.org
[GEMET]: https://www.eionet.europa.eu/gemet/
[Collecting]: https://github.com/omeka-s-modules/Collecting
[fork of the module Collecting]: https://gitlab.com/Daniel-KM/Omeka-S-module-Collecting
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Thesaurus/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Ontologie du christianisme médiéval en images]: https://omci.inha.fr
[Institut national d’histoire de l’art]: https://www.inha.fr
[ConsiliaWeb]: https://www.conseil-etat.fr/avis-consultatifs/rechercher-un-avis-consiliaweb
[Conseil d’État]: https://www.conseil-etat.fr
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
