Thesaurus (module for Omeka S)
==============================

[Thesaurus] is a module for [Omeka S] that allows to use standard thesaurus
(ISO 25964: Thesauri and interoperability with other vocabularies) inside Omeka
in order to describe documents.


Installation
------------

First, install the module [Custom Vocab].

Uncompress files and rename module folder `Thesaurus`. Then install it like any
other Omeka module and follow the config instructions.

See general end user documentation for [Installing a module].


Usage
-----

Currently, the module can only convert a flat list into a flat thesaurus to be
used with CustomVocab. Just go to `https://example.org/admin/thesaurus/convert`.

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

Then, you can add it as a custom vocab.


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


Contact
-------

Current maintainers:

* Daniel Berthereau (see [Daniel-KM] on GitHub)


Copyright
---------

* Copyright Daniel Berthereau, 2018


[Omeka S]: https://omeka.org/s
[Thesaurus]: https://github.com/Daniel-KM/Omeka-S-module-Thesaurus
[Installing a module]: https://omeka.org/s/docs/user-manual/modules/#installing-modules
[Custom Vocab]: https://github.com/omeka-s-modules/CustomVocab
[module issues]: https://github.com/Daniel-KM/Omeka-S-module-Thesaurus/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
