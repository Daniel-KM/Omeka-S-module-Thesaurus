$(document).ready( function() {

// Initialize the thesaurus tree.
var tree = $('#thesaurus-tree');
tree.jstree({
    'core': {
        'check_callback': true,
        'force_text': true,
        'data': tree.data('jstree-data'),
    },
}).on('loaded.jstree', function() {
    // Open all nodes by default.
    tree.jstree(true).open_all();
});

});
