Thesaurus (module for Omeka S)
==============================

> __New versions of this module and support for Omeka S version 3.0 and above
> are available on [GitLab], which seems to respect users and privacy better
> than the previous repository.__

[Thesaurus] is a module for [Omeka S] that allows to manage a standard thesaurus
(ontology [skos]) to describe documents with a tree structure (_arborescence_):

- the skos ontology is included;
- a resource template to build the thesaurus as a list of items (recommended);
- an admin view to manage the tree structure;
- a view helper to display the tree of concepts in the theme, or part of it.
- a block template to display the thesaurus as a tree (via module [Block Plus]).

The view helper can be used for any purpose, for example to build a hierarchical
list of item sets, but this is not the main purpose.

The thesaurus is available as a endpoint for [Value Suggest], through module [Value Suggest: Any].

A future version may rely on ISO 25964 (Thesauri and interoperability with
other vocabularies).


Installation
------------

Optional modules are [Generic], [Block Plus], and [Value Suggest: Any].

Uncompress files and rename module folder `Thesaurus`. Then install it like any
other Omeka module and follow the config instructions.

See general end user documentation for [Installing a module].


Usage
-----

This module allows to manage concept as any other items and to use and to manage
directly the terms of the ontology [skos].

### Use of concepts as related items

You can create your own thesaurus (or import it via module such [Bulk Import]).
For that, use the integrated ontology `skos`, that contains the classes and the
properties to manage items as concepts.

Then, in your theme, use the various methods of the view helper `$this->thesaurus($item)`
in order to display full tree, ascendants, descendants, siblings, etc.

### Page blocks

A site block "Thesaurus" is available to include the thesaurus on any page, or a
part of it (branch, narrowers, ascendants, descendants, etc.).

A template is added for the simple block of module [Block Plus] too. Just set
`item = id` where id is the thesaurus you want to display.

### Create a select with the tree, in particular for the module Collecting

To add a select where the user will be able to choose terms, you need to use a
[fork of the module Collecting], which commits will be integrated upstream soon.
Then choose a property to fill, the input type "resource item", then the query:
`resource_class_id[0]=xxx&property[0][joiner]=and&property[0][property]=skos:inScheme&property[0][type]=res&property[0][text]=yyy&sort_by=thesaurus&sort_thesaurus=yyy`
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
            'text' => yyy,
        ],
    ],
    'sort_by' => 'thesaurus',
    'sort_thesaurus' => yyy,
```
Here, `xxx` is the resource class id of `skos:ConceptScheme` and `yyy` is the
item id of the scheme.

### Controller plugin and view helper `thesaurus()`

The controller or view helper thesaurus() allows to create a thesaurus and to
get the whole tree, the tops, any branch, the ascendants, the descendants, etc.
It can be used in the theme or anywhere else.

To build a thesaurus, use `$thesaurus = $this->thesaurus($item)`, where item is
the scheme or any concept. Then, you can get any data for it with the methods.

To get data for any other concept of the thesaurus, set it first. For example,
to get the  ascendants for a concept, use `$this->thesaurus()->setItem($item)->ascendants()`.

The view helper has the specific method `display()` to display the tree, a list,
or anything else via a view template.


TODO
----

* [ ] Manage terms as a full resources, separetly from items (like Annotation)? No.
* [ ] Manage representation when a term belongs to multiple thesaurus? Probably useless with association.
* [ ] Implement a tree iterator in representation, plugin and helper.
* [ ] Uninstall vocabulary and resources templates if not used.


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

* Copyright Daniel Berthereau, 2018-2022 (see [Daniel-KM] on GitLab)

First version of this module was developed for the project [Ontologie du christianisme médiéval en images]
for the [Institut national d’histoire de l’art] (INHA).


[Omeka S]: https://omeka.org/s
[Thesaurus]: https://gitlab.com/Daniel-KM/Omeka-S-module-Thesaurus
[skos]: https://www.w3.org/2004/02/skos
[Installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Custom Vocab]: https://github.com/omeka-s-modules/CustomVocab
[Block Plus]: https://gitlab.com/Daniel-KM/Omeka-S-module-BlockPlus
[Value Suggest]: https://github.com/omeka-s-modules/ValueSuggest
[Value Suggest: Any]: https://gitlab.com/Daniel-KM/Omeka-S-module-ValueSuggestAny
[LibreOffice]: https://libreoffice.org
[fork of the module Collecting]: https://gitlab.com/Daniel-KM/Omeka-S-module-Collecting
[module issues]: https://gitlab.com/Daniel-KM/Omeka-S-module-Thesaurus/-/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Ontologie du christianisme médiéval en images]: https://omci.inha.fr
[Institut national d’histoire de l’art]: https://www.inha.fr
[GitLab]: https://gitlab.com/Daniel-KM
[Daniel-KM]: https://gitlab.com/Daniel-KM "Daniel Berthereau"
