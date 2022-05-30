/**
 * Manage the case where Omeka is installed in a subdirectory.
 */
const basePath = window.location.pathname.substring(0, window.location.pathname.indexOf('/admin/')) + '/';

$(document).ready( function() {

    // Initialize the thesaurus tree.
    const tree = $('#jstree');
    if (!tree.jstree) return;

    var initialTreeData;

    /**
     * Display element plugin for jsTree.
     * Adapted from jstree-plugins.
     */
    $.jstree.plugins.displayElement = function(options, parent) {
       // Use a <i> instead of a <a> because inside a <a>.
        var displayIcon = $('<i>', {
            class: 'jstree-icon jstree-displaylink',
            attr:{role: 'presentation'}
        });
        this.bind = function() {
            parent.bind.call(this);
            this.element.on(
                'click.jstree',
                '.jstree-displaylink',
                $.proxy(function(e) {
                    var icon = $(e.currentTarget);
                    var node = icon.closest('.jstree-node');
                    var nodeObj = this.get_node(node);
                    var nodeUrl = nodeObj.data.url;
                    window.open(nodeUrl, '_blank');
                }, this)
            );
        };
        this.redraw_node = function(node, deep, is_callback, force_render) {
            node = parent.redraw_node.apply(this, arguments);
            if (node) {
                var nodeObj = this.get_node(node);
                var nodeUrl = nodeObj.data.url;
                if (nodeUrl) {
                    var nodeJq = $(node);
                    var anchor = nodeJq.children('.jstree-anchor');
                    let anchorClone = displayIcon.clone();
                    anchorClone.attr('title', 'item #' + nodeObj.id);
                    anchor.append(anchorClone);
                }
            }
            return node;
        };
    };

    // Add "data" to be be able to load core plugins, and include item url.
    tree.data('jstree-data')
        .forEach(function(element, index) {
            this[index].data = {
                url: basePath + 'admin/item/' + element.id,
            };
        }, tree.data('jstree-data'));

    var jstree = tree
        .jstree({
            'core': {
                'check_callback': true,
                'force_text': true,
                'data': tree.data('jstree-data'),
            },
            // Plugins jstree and omeka (jstree-plugins).
            'plugins': ['dnd', 'removenode', /*'editlink'*/, 'displayElement']
        })
        .on('loaded.jstree', function() {
            // Open all nodes by default.
            tree.jstree(true).open_all();
            initialTreeData = JSON.stringify(tree.jstree(true).get_json());
        })
        .on('move_node.jstree', function(e, data) {
            // Open node after moving it.
            var parent = tree.jstree(true).get_node(data.parent);
            tree.jstree(true).open_all(parent);
        });

    $('#thesaurus-tree-form')
        .on('o:before-form-unload', function () {
            if (initialTreeData !== JSON.stringify(tree.jstree(true).get_json())) {
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

});
