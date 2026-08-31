/**
 * Manage the case where Omeka is installed in a subdirectory.
 */
const basePath = window.location.pathname.substring(0, window.location.pathname.indexOf('/admin/')) + '/';

$(document).ready( function() {

    // Initialize the thesaurus tree.
    const tree = $('#jstree');
    if (!tree.jstree) return;

    const isEdit = $('body').hasClass('edit');

    var initialTreeData;

    // Disable button "save" until the menu is fully loaded
    // to avoid to override it with an empty menu.
    const buttonSave = $('body.edit.menus #page-actions button[type=submit]');
    buttonSave.prop('disabled', true);

    /**
     * Display element plugin for jsTree.
     * Adapted from jstree-plugins.
     */
    $.jstree.plugins.displayElements = function(options, parent) {
        // The url of the concepts is built by the server, so the routes and the
        // base path are always right.
        const conceptUrl = $('#jstree').data('concept-url');
        const urlConcept = function (id, action) {
            return conceptUrl + '/' + id + (action ? '/' + action : '');
        };
        // Use a <i> instead of a <a> because inside a <a>.
        var editIcon = $('<i>', {
            class: 'jstree-icon jstree-editlink',
            attr: {role: 'presentation'}
        });
        var displayIcon = $('<i>', {
            class: 'jstree-icon jstree-displaylink',
            attr: {role: 'presentation'}
        });
        this.bind = function() {
            parent.bind.call(this);
            this.element.on(
                'click.jstree',
                '.jstree-editlink, .jstree-displaylink',
                $.proxy(function(e) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    var icon = $(e.currentTarget);
                    var nodeObj = this.get_node(icon.closest('.jstree-node'));
                    var action = icon.hasClass('jstree-editlink') ? 'edit' : null;
                    window.open(urlConcept(nodeObj.id, action), '_blank');
                }, this)
            );
        };
        this.redraw_node = function(node, deep, is_callback, force_render) {
            node = parent.redraw_node.apply(this, arguments);
            if (node && conceptUrl) {
                var nodeObj = this.get_node(node);
                var anchor = $(node).children('.jstree-anchor');
                let editClone = editIcon.clone();
                editClone.attr('title', 'concept #' + nodeObj.id + ' (edit)');
                anchor.append(editClone);
                let displayClone = displayIcon.clone();
                displayClone.attr('title', 'concept #' + nodeObj.id);
                anchor.append(displayClone);
            }
            return node;
        };
    };

    tree
        .jstree({
            'core': {
                'check_callback': true,
                'force_text': true,
                // Get jstree data from attributes when an error occurs (not yet saved).
                // Add "data" to be be able to load core plugins, and include item url.
                data: tree.data('jstree-data')
                    ? tree.data('jstree-data')
                    : {
                        // Only an url for the root node.
                        url: tree.data('jstree-url'),
                    },
            },
            // Plugins jstree, omeka (jstree-plugins) or above.
            // TODO Use lazy massload? Useless until 10000 concepts.
            plugins: isEdit
                ? ['dnd', 'removenode', /* 'editlink' */, 'displayElements']
                : ['displayElements'],
        })
        .on('loaded.jstree', function() {
            // Open all nodes by default.
            tree.jstree(true).open_all();
            // Don't store node state open/closed, since it's not stored.
            initialTreeData = JSON.stringify(tree.jstree(true).get_json(null, {no_state: true, no_a_attr: true, no_li_attr: true}));
            buttonSave.prop('disabled', false);
        })
        .on('move_node.jstree', function(e, data) {
            // Open parent node after moving it.
            var parent = tree.jstree(true).get_node(data.parent);
            tree.jstree(true).open_all(parent);
        });

    $('#thesaurus-tree-form')
        .on('o:before-form-unload', function () {
            if (initialTreeData !== JSON.stringify(tree.jstree(true).get_json(null, {no_state: true, no_a_attr: true, no_li_attr: true}))) {
                Omeka.markDirty(this);
            }
        });

    $('#thesaurus-tree-form')
        .on('submit', $.proxy(function() {
            // Only id, parent and data are useful, but it's not possible in Omeka
            // version to remove other keys.
            let currentTree = $.jstree.reference('#jstree').get_json(null, {
                no_text: true,
                no_icon: true,
                no_state: true,
                no_li_attr: true,
                no_a_attr: true,
                flat: true,
            })
            currentTree.forEach(function(element, index) {
                delete this.text;
                delete this.icon;
            }, currentTree);
            $('<input>', {
                type: 'hidden',
                name: 'jstree',
                val: JSON.stringify(currentTree),
            }).appendTo('#thesaurus-tree-form');
        }, this));

    $('#tree-toggle-all').on('click', function () {
        var $button = $(this);
        if ($button.attr('data-state') === 'open') {
            tree.jstree(true).close_all();
            $button.attr('data-state', 'closed').text($button.attr('data-label-open'));
        } else {
            tree.jstree(true).open_all();
            $button.attr('data-state', 'open').text($button.attr('data-label-close'));
        }
    });

});
