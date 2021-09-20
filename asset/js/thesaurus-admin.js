$(document).ready( function() {

// Initialize the thesaurus tree.
var tree = $('#jstree');
var initialTreeData;

// Add "data" to be be able to load core plugins.
tree.data('jstree-data')
    .forEach(function(element, index) {
        this[index].data = {};
    }, tree.data('jstree-data'));

var jstree = tree.jstree({
    'core': {
        'check_callback': true,
        'force_text': true,
        'data': tree.data('jstree-data'),
    },
    'plugins': ['dnd', 'removenode', /* 'editlink' */, 'display']
}).on('loaded.jstree', function() {
    // Open all nodes by default.
    tree.jstree(true).open_all();
    initialTreeData = JSON.stringify(tree.jstree(true).get_json());
}).on('move_node.jstree', function(e, data) {
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
