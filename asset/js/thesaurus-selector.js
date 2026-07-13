/**
 * Concept selector for the thesaurus data types in the resource form.
 *
 * The prototype holds no data. Each widget fetches concepts lazily from its own
 * suggest endpoint (type-ahead, direct access) and jstree endpoint (browse in a
 * dialog), so the form scales whatever the size and the number of thesaurus.
 *
 * The type-ahead follows the aria combobox pattern and the dialog follows the
 * standard dialog of the module Common, so it is natively accessible.
 */
(function () {
    var DEBOUNCE = 250;
    var uid = 0;

    /**
     * Give the listbox a unique id, since the prototype is cloned by value.
     */
    function initAria(selector) {
        var results = selector.find('.thesaurus-results');
        if (results.attr('id')) {
            return;
        }
        var id = 'thesaurus-results-' + (++uid);
        results.attr('id', id);
        selector.find('.thesaurus-input').attr('aria-controls', id);
    }

    /**
     * Show the selected concept as a chip and hide the search, or the opposite.
     * The browse button always stays available to change the concept.
     */
    function setSelection(selector, id, title, ascendance) {
        var chip = selector.find('.thesaurus-chip');
        var search = selector.find('.thesaurus-search');
        selector.find('input.value').val(id || '');
        if (id) {
            chip.find('.thesaurus-chip-title').text(title || '');
            chip.find('.thesaurus-chip-ascendance').text(ascendance || '');
            chip.prop('hidden', false);
            search.prop('hidden', true);
            closeResults(selector);
        } else {
            chip.prop('hidden', true);
            search.prop('hidden', false);
            selector.find('.thesaurus-input').val('');
        }
    }

    function closeResults(selector) {
        selector.find('.thesaurus-results').prop('hidden', true).empty();
        selector.find('.thesaurus-input')
            .attr('aria-expanded', 'false')
            .removeAttr('aria-activedescendant');
    }

    /**
     * Render the suggestions as a list of options with their breadcrumb.
     */
    function renderResults(selector, items) {
        var results = selector.find('.thesaurus-results').empty();
        var input = selector.find('.thesaurus-input');
        if (!items.length) {
            results.prop('hidden', true);
            input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
            return;
        }
        var listId = results.attr('id');
        items.forEach(function (item, index) {
            var li = $('<li>', {
                'class': 'thesaurus-result',
                'id': listId + '-' + index,
                'role': 'option',
                'aria-selected': 'false',
                'data-id': item.id,
                'data-title': item.title,
                'data-ascendance': item.ascendance || '',
            });
            $('<span>', {'class': 'thesaurus-result-title', text: item.title}).appendTo(li);
            if (item.ascendance) {
                $('<span>', {'class': 'thesaurus-result-ascendance', text: item.ascendance}).appendTo(li);
            }
            results.append(li);
        });
        results.prop('hidden', false);
        input.attr('aria-expanded', 'true');
    }

    function activate(selector, li) {
        selector.find('.thesaurus-result.active')
            .removeClass('active')
            .attr('aria-selected', 'false');
        if (!li || !li.length) {
            selector.find('.thesaurus-input').removeAttr('aria-activedescendant');
            return;
        }
        li.addClass('active').attr('aria-selected', 'true');
        selector.find('.thesaurus-input').attr('aria-activedescendant', li.attr('id'));
        var box = li.parent()[0];
        var el = li[0];
        if (el.offsetTop < box.scrollTop) {
            box.scrollTop = el.offsetTop;
        } else if (el.offsetTop + el.offsetHeight > box.scrollTop + box.clientHeight) {
            box.scrollTop = el.offsetTop + el.offsetHeight - box.clientHeight;
        }
    }

    function chooseResult(li) {
        var selector = li.closest('.thesaurus-selector');
        setSelection(selector, li.data('id'), li.data('title'), li.data('ascendance'));
    }

    function search(selector) {
        var query = selector.find('.thesaurus-input').val();
        // Ignore stale responses: only the last request updates the results.
        var token = (selector.data('request') || 0) + 1;
        selector.data('request', token);
        $.get(selector.data('suggest-url'), {q: query}, function (data) {
            if (selector.data('request') !== token) {
                return;
            }
            renderResults(selector, (data && data.results) || []);
        });
    }

    // Init a value: on edit the core fills the hidden input by data-value-key,
    // so paint the chip from the value object; else start on the search field.
    $(document).on('o:prepare-value', function (e, dataType, value, valueObj) {
        if (typeof dataType !== 'string' || dataType.indexOf('thesaurus:') !== 0) {
            return;
        }
        var selector = value.find('.thesaurus-selector');
        if (!selector.length) {
            return;
        }
        initAria(selector);
        if (valueObj && valueObj.value_resource_id) {
            setSelection(selector, valueObj.value_resource_id,
                valueObj.display_title || Omeka.jsTranslate('[Untitled]'), '');
        } else {
            setSelection(selector, null);
        }
    });

    var debounce;
    $(document).on('input', '.thesaurus-input', function () {
        var selector = $(this).closest('.thesaurus-selector');
        clearTimeout(debounce);
        debounce = setTimeout(function () { search(selector); }, DEBOUNCE);
    });

    $(document).on('focus', '.thesaurus-input', function () {
        var selector = $(this).closest('.thesaurus-selector');
        if (selector.find('.thesaurus-result').length === 0) {
            search(selector);
        }
    });

    $(document).on('keydown', '.thesaurus-input', function (e) {
        var selector = $(this).closest('.thesaurus-selector');
        var active = selector.find('.thesaurus-result.active');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            var next = active.length ? active.next('.thesaurus-result') : $();
            activate(selector, next.length ? next : selector.find('.thesaurus-result').first());
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activate(selector, active.prev('.thesaurus-result'));
        } else if (e.key === 'Enter') {
            if (active.length) {
                e.preventDefault();
                chooseResult(active);
            }
        } else if (e.key === 'Escape') {
            closeResults(selector);
        }
    });

    $(document).on('mousedown', '.thesaurus-result', function (e) {
        // mousedown, not click, to fire before the input blur closes the list.
        e.preventDefault();
        chooseResult($(this));
    });

    $(document).on('blur', '.thesaurus-input', function () {
        var selector = $(this).closest('.thesaurus-selector');
        setTimeout(function () { closeResults(selector); }, 150);
    });

    // Browse the thesaurus tree in a shared dialog (jstree), for discovery.

    var currentSelector = null;

    function buildDialog() {
        if ($('#thesaurus-browser').length) {
            return;
        }
        var close = Omeka.jsTranslate('Close');
        var search = Omeka.jsTranslate('Search');
        $('body').append(`
            <dialog id="thesaurus-browser" class="dialog-common thesaurus-browser"
                aria-labelledby="thesaurus-browser-heading">
                <div class="dialog-background">
                    <div class="dialog-panel">
                        <div class="dialog-header">
                            <button type="button" class="dialog-header-close-button thesaurus-browser-close">
                                <span class="dialog-close" aria-hidden="true">🗙</span>
                                <span class="dialog-close-label">${close}</span>
                            </button>
                        </div>
                        <div class="dialog-contents">
                            <div class="dialog-heading"><h2 id="thesaurus-browser-heading"></h2></div>
                            <div class="dialog-body">
                                <div class="thesaurus-browser-toolbar">
                                    <input type="search" class="thesaurus-browser-search"
                                        placeholder="${search}" aria-label="${search}">
                                    <button type="button" class="thesaurus-browser-toggle button"
                                        aria-controls="thesaurus-browser-tree"></button>
                                </div>
                                <div id="thesaurus-browser-tree" class="thesaurus-browser-tree"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </dialog>
        `);
    }

    function closeDialog() {
        var dialog = document.getElementById('thesaurus-browser');
        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    function openDialog(selector, heading) {
        buildDialog();
        currentSelector = selector;
        var dialog = document.getElementById('thesaurus-browser');
        var tree = $('#thesaurus-browser-tree');
        $('#thesaurus-browser-heading').text(heading);
        $('.thesaurus-browser-search').val('');

        // The tree opens collapsed, so the button offers to open it.
        $('.thesaurus-browser-toggle')
            .attr('data-state', 'closed')
            .attr('data-label-open', selector.data('label-open'))
            .attr('data-label-close', selector.data('label-close'))
            .text(selector.data('label-open'));

        if (tree.jstree(true)) {
            tree.jstree('destroy');
        }
        tree.jstree({
            core: {
                data: {url: selector.data('jstree-url'), dataType: 'json'},
                themes: {dots: true, icons: false},
                multiple: false,
            },
            plugins: ['search'],
            search: {show_only_matches: true, show_only_matches_children: true},
        });

        // jstree 3.3 only passes "show_only_matches" in the event:
        // the plugin does not filter by itself, so hide the nodes that do not 
        // match, with their ancestors kept to preserve the hierarchy.
        tree.off('search.jstree').on('search.jstree', function (e, data) {
            var container = $(this);
            container.find('li.jstree-node').hide();
            data.nodes.each(function () {
                var node = $(this);
                node.show()
                    .parentsUntil(container, 'li.jstree-node').show();
                node.find('li.jstree-node').show();
            });
        });

        tree.off('clear_search.jstree').on('clear_search.jstree', function () {
            $(this).find('li.jstree-node').show();
        });

        tree.off('select_node.jstree').on('select_node.jstree', function (e, data) {
            var titles = data.node.parents
                .filter(function (id) { return id !== '#'; })
                .map(function (id) { return data.instance.get_node(id).text; })
                .reverse();
            setSelection(currentSelector, data.node.id, data.node.text, titles.join(' › '));
            closeDialog();
        });

        // The native dialog brings the backdrop, the focus trap and Escape.
        dialog.showModal();
        // Focus the filter: it is the quickest way into a large thesaurus.
        $('.thesaurus-browser-search').trigger('focus');
    }

    $(document).on('click', '.thesaurus-browse', function (e) {
        e.preventDefault();
        var button = $(this);
        openDialog(button.closest('.thesaurus-selector'), button.attr('title'));
    });

    $(document).on('click', '.thesaurus-browser-close', closeDialog);

    // Close when clicking outside of the panel.
    $(document).on('click', '#thesaurus-browser .dialog-background', function (e) {
        if (e.target === this) {
            closeDialog();
        }
    });

    $(document).on('close', '#thesaurus-browser', function () {
        currentSelector = null;
    });

    $(document).on('click', '.thesaurus-browser-toggle', function () {
        var button = $(this);
        var tree = $('#thesaurus-browser-tree').jstree(true);
        if (button.attr('data-state') === 'open') {
            tree.close_all();
            button.attr('data-state', 'closed').text(button.attr('data-label-open'));
        } else {
            tree.open_all();
            button.attr('data-state', 'open').text(button.attr('data-label-close'));
        }
    });

    var searchDebounce;
    $(document).on('input', '.thesaurus-browser-search', function () {
        var query = this.value;
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(function () {
            $('#thesaurus-browser-tree').jstree(true).search(query);
        }, DEBOUNCE);
    });
})();
