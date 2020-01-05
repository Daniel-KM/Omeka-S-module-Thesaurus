Thesaurus (module for Omeka S)
==============================

[Thesaurus] is a module for [Omeka S] that contains helpers to manage a standard
thesaurus (ontology [skos]) to describe documents.

The helpers are:
- the skos ontology is included;
- convert tool from a hierarchical list of descriptors to a flat list for [Custom vocab]
  (deprecated);
- a resource template to build the thesaurus as a list of items (recommended);
- a view helper to display the tree of concepts in the theme, or part of it.

The view helper can be used for any purpose, for example to build a hierarchical
list of item sets.

A future version may rely on ISO 25964 (Thesauri and interoperability with
other vocabularies).


Installation
------------

Uncompress files and rename module folder `Thesaurus`. Then install it like any
other Omeka module and follow the config instructions.

See general end user documentation for [Installing a module].


Usage
-----

This module can be used in two ways: the thesaurus can be a custom vocab or a
list of items with ontology [skos].

### Convert a thesaurus into a flat list

**Warning**: This part of the module will be moved into another module in next
version.

The use of a thesaurus as a custom vocab allows to set it as subject in the
resource template, so it helps to normalize the subjects.

To convert a flat list into a flat thesaurus, just got to /admin/thesaurus/convert.

The input file should be a hierarchical text, with tabulation that indicate the
hierarchy:

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

Such a table can be created easily with a text editor or [LibreOffice] Calc
(export as csv with tabulation as delimiter).

The output will be a flat thesaurus:

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

Then, you can add it as a custom vocab. In the theme, use the module [Metadata Browse]
to create links automatically for the subjects.

### Use of concepts as related items

You can create your own thesaurus (or import it via module such [Bulk Import]).
For that, use the integrated ontology `skos`, that contains the classes and the
properties to manage items as concepts.

Then, in your theme, use the various methods of the view helper `$this->thesaurus($item)`
in order to display full tree, ascendants, descendants, siblings, etc.


TODO
----

* Optimize structure building via direct queries to the database. See module EAD.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitHub.


License
-------

This module is published under the [CeCILL v2.1] licence, compatible with
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

* Copyright Daniel Berthereau, 2018-2019 (see [Daniel-KM] on GitHub)

First version of this module was developed for the project [Ontologie du christianisme médiéval en images]
for the [Institut national d’histoire de l’art] (INHA).


[Omeka S]: https://omeka.org/s
[Thesaurus]: https://github.com/Daniel-KM/Omeka-S-module-Thesaurus
[skos]: https://www.w3.org/2004/02/skos
[Installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Custom Vocab]: https://github.com/omeka-s-modules/CustomVocab
[LibreOffice]: https://libreoffice.org
[module issues]: https://github.com/Daniel-KM/Omeka-S-module-Thesaurus/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Ontologie du christianisme médiéval en images]: https://omci.inha.fr
[Institut national d’histoire de l’art]: https://www.inha.fr
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
