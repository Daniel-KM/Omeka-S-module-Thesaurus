<?php declare(strict_types=1);

namespace Thesaurus\Controller\Admin;

use Common\Stdlib\PsrMessage;
use finfo;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Controller\Admin\ItemController;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Mvc\Exception\RuntimeException;
use Thesaurus\Form\ConfirmAllForm;
use Thesaurus\Form\ConvertForm;
use Thesaurus\Form\UpdateConceptsForm;

/**
 * Note: Use item templates as default.
 */
class ThesaurusController extends ItemController
{
    public function searchAction()
    {
        return parent::searchAction()
            ->setTemplate('omeka/admin/item/search');
    }

    public function showAction()
    {
        $response = $this->api()->read('items', $this->params('id'));
        $item = $response->getContent();

        // Check if the thesaurus of the item has a collection, that is
        // required to make custom vocab working for thesaurus in resource form.
        /** @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus $thesaurus */
        $thesaurus = $this->thesaurus($item);
        if (!$thesaurus->getItemSet()) {
            $this->messenger()->addWarning(
                'The thesaurus has no item set with class "skos:Collection" or "skos:OrderedCollection".' // @translate
            );
        }

        return new ViewModel([
            'item' => $item,
            'resource' => $item,
        ]);
    }

    public function showDetailsAction()
    {
        return parent::showDetailsAction()
            ->setTemplate('omeka/admin/item/show-details');
    }

    public function sidebarSelectAction()
    {
        return parent::sidebarSelectAction()
            ->setTemplate('omeka/admin/item/sidebar-select');
    }

    public function deleteConfirmAction()
    {
        $view = parent::deleteConfirmAction();

        $form = $this->getForm(ConfirmAllForm::class);
        $form->setAttribute(
            'action',
            $this->url()->fromRoute('admin/thesaurus/id', ['action' => 'delete', 'id' => $view->getVariable('resource')->id()], true)
        );

        return $view
            ->setTemplate('common/delete-all-confirm-details')
            ->setVariable('resourceLabel', 'thesaurus') // @translate
            ->setVariable('form', $form);
    }

    public function deleteAction()
    {
        $request = $this->getRequest();
        if ($request->isPost()) {
            $post = $request->getPost();
            $form = $this->getForm(ConfirmAllForm::class);
            $form->setData($post);
            if ($form->isValid()) {
                /** @var \Omeka\Mvc\Controller\Plugin\Api $api */
                $api = $this->api($form);
                $id = (int) $this->params('id');
                try {
                    $scheme = $this->api->read('items', ['id' => $id])->getContent();
                } catch (\Throwable $e) {
                    $scheme = null;
                }
                if (!$scheme) {
                    $this->messenger()->addError(new PsrMessage(
                        'The item #{item_id} is not available.', // @translate
                        ['item_id' => $id]
                    ));
                } else {
                    /** @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus $thesaurus */
                    $thesaurus = $this->thesaurus($scheme);
                    if (!$thesaurus->isSkos()) {
                        $this->messenger()->addError(new PsrMessage(
                            'The item #{item_id} does not belong to a thesaurus.', // @translate
                            ['item_id' => $id]
                        ));
                    } else {
                        $data = $form->getData();
                        $mode = $data['mode'] ?? 'scheme';
                        if ($mode === 'scheme') {
                            $response = $this->api($form)->delete('items', $id);
                            if ($response) {
                                $this->messenger()->addSuccess('Thesaurus scheme successfully deleted'); // @translate
                            }
                        } else {
                            if ($mode === 'full') {
                                $itemSet = $thesaurus->getItemSet();
                                if ($itemSet) {
                                    $api->delete('item_sets', $itemSet->id());
                                }
                            }
                            $ids = $thesaurus->flatTree();
                            $ids = array_keys($ids);
                            $ids[] = $id;
                            $response = $api->batchDelete('items', $ids);
                            if ($response) {
                                $mode === 'concepts'
                                    ? $this->messenger()->addSuccess('Full thesaurus successfully deleted') // @translate
                                    : $this->messenger()->addSuccess('Full thesaurus and item set successfully deleted'); // @translate
                            }
                        }
                    }
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }
        return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'browse'], true);
    }

    public function batchEditAction()
    {
        $result = parent::batchEditAction();
        return $result instanceof ViewModel
            ? $result->setTemplate('omeka/admin/item/batch-edit')
            : $result;
    }

    public function batchEditAllAction()
    {
        $result = parent::batchEditAllAction();
        return $result instanceof ViewModel
            ? $result->setTemplate('omeka/admin/item/batch-edit-all')
            : $result;
    }

    /**
     * @see \Menu\Controller\SiteAdmin\MenuController::jstreeAction()
     */
    public function jstreeAction()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            throw new NotFoundException();
        }

        // Automatically throw exception when not found.
        $id = (int) $this->params()->fromRoute('id');
        $item = $this->api()->read('items', ['id' => $id])->getContent();

        /** @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus $thesaurus */
        $thesaurus = $this->thesaurus($item);
        if (!$thesaurus->isSkos()) {
            throw new RuntimeException(new PsrMessage(
                'Item #{item_id} is not a skos scheme and is not a thesaurus.', // @translate
                ['item_id' => $id]
            ));
        }

        return new JsonModel(
            $thesaurus->jsFlatTree()
        );
    }

    public function updateAction()
    {
        /** @var \Omeka\Api\Representation\ItemRepresentation $item */
        $item = $this->api()->read('items', $this->params('id'))->getContent();

        if (!$item->userIsAllowed('batch-edit')) {
            $message = 'User is not allowed to batch edit thesaurus.'; // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/thesaurus/id', ['action' => 'browse'], true);
        }

        $this->processUpdateConcepts($item);
        return $this->redirect()->toRoute('admin/thesaurus/id', ['action' => 'show'], true);
    }

    public function updateConceptsAction()
    {
        /** @var \Omeka\Api\Representation\ItemRepresentation $item */
        $item = $this->api()->read('items', $this->params('id'))->getContent();

        if (!$item->userIsAllowed('batch-edit')) {
            $message = 'User is not allowed to batch edit thesaurus.'; // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/thesaurus/id', ['action' => 'show'], true);
        }

        $form = $this->getForm(UpdateConceptsForm::class);
        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);
            if ($form->isValid()) {
                $data = $form->getData();
                $this->processUpdateConcepts($item, $data['mode'] ?? 'replace');
                return $this->redirect()->toRoute('admin/thesaurus/id', ['action' => 'show'], true);
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'item' => $item,
            'resource' => $item,
            'form' => $form,
        ]);
    }

    public function reindexAction()
    {
        /** @var \Omeka\Api\Representation\ItemRepresentation $item */
        $item = $this->api()->read('items', $this->params('id'))->getContent();

        /** @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus $thesaurus */
        /* // Don't check thesaurus if not indexed, it can be memory intensive.
        $thesaurus = $this->thesaurus($item);
        if (!$thesaurus->isSkos()) {
            $message = new PsrMessage(
                'The item #{item_id} does not belong to a thesaurus.', // @translate
                ['item_id' => $item->id()]
            );
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/thesaurus/default');
        }
        */

        $dispatcher = $this->jobDispatcher();
        $args = [
            // 'scheme' => (int) $thesaurus->scheme()->id(),
            'scheme' => (int) $item->id(),
        ];
        $job = $dispatcher->dispatch(\Thesaurus\Job\IndexThesaurus::class, $args);
        $message = new PsrMessage(
            'Indexing concepts in background ({link}job #{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link' => sprintf('<a href="%s">', htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', $this->url()->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : sprintf('<a href="%1$s" target="_blank">', $this->url()->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
        return $this->redirect()->toRoute('admin/thesaurus/default');
    }

    public function structureAction()
    {
        /** @var \Omeka\Api\Representation\ItemRepresentation $item */
        $item = $this->api()->read('items', $this->params('id'))->getContent();

        if ($item->userIsAllowed('batch-edit')) {
            $form = $this->getForm(\Laminas\Form\Form::class)
                ->setAttribute('id', 'thesaurus-tree-form');
            if ($this->getRequest()->isPost()) {
                $formData = $this->params()->fromPost();
                if (!empty($formData['jstree'])) {
                    $jstree = json_decode($formData['jstree'], true);
                    $form->setData($formData);
                    if ($form->isValid() && is_array($jstree)) {
                        $this->updateThesaurusStructure($item, $jstree);
                        $message = new PsrMessage(
                            'You may need to reload {link}this page{link_end} a second time to clean indexation of top concepts.', // @translate
                            [
                                'link' => sprintf('<a href="%s">', htmlspecialchars($this->url()->fromRoute('admin/thesaurus/id', [], true))),
                                'link_end' => '</a>',
                            ]
                        );
                        $message->setEscapeHtml(false);
                        $this->messenger()->addWarning($message);
                    } else {
                        $this->messenger()->addFormErrors($form);
                    }
                }
            }
        } else {
            $form = null;
        }

        return new ViewModel([
            'item' => $item,
            'resource' => $item,
            'form' => $form,
        ]);
    }

    public function convertAction()
    {
        /** @var \Thesaurus\Form\ConvertForm $form */
        $form = $this->getForm(ConvertForm::class);
        $form
            ->setAttribute('action', $this->url()->fromRoute('admin/thesaurus/default', ['action' => 'upload']))
            ->init();
        return new ViewModel([
            'form' => $form,
        ]);
    }

    public function uploadAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            $this->messenger()->addError(
                'Unallowed request.' // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'browse'], true);
        }

        $post = $this->params()->fromPost();

        // Re-submission from the preview screen: the previewed flat list is the
        // single source for the download and the imports, so what is seen is
        // what is imported.
        if (isset($post['result']) && trim((string) $post['result']) !== '') {
            return $this->processPreview($post);
        }

        $files = $request->getFiles()->toArray();
        $uploadError = (int) ($files['file']['error'] ?? \UPLOAD_ERR_NO_FILE);

        // Detect the upload size error before the form validation, so the
        // message is explicit when the file exceeds the server upload size.
        if (in_array($uploadError, [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            $this->messenger()->addError(new PsrMessage(
                'The file exceeds the maximum upload size allowed by the server ({size}).', // @translate
                ['size' => ini_get('upload_max_filesize')]
            ));
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert'], true);
        }

        /** @var \Thesaurus\Form\ConvertForm $form */
        $form = $this->getForm(ConvertForm::class);
        $form->setData($post + $files);
        if (!$form->isValid()) {
            $this->messenger()->addError(
                'Wrong request for file.' // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert'], true);
        }

        $settings = $this->settings();

        $data = $form->getData();

        // The source is either an uploaded file or a remote URL.
        $url = trim((string) ($data['url'] ?? ''));
        if ($uploadError === \UPLOAD_ERR_OK) {
            $file = $files['file'];
        } elseif ($url !== '') {
            $file = $this->fetchUrl($url);
            if (!$file) {
                return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert'], true);
            }
        } else {
            $this->messenger()->addError(
                'A file or a URL is required.' // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert'], true);
        }

        $inputFormat = $data['format'] ?? 'skos';
        $destination = $data['destination'] ?? 'preview';
        $separator = $settings->get('thesaurus_separator', \Thesaurus\Module::SEPARATOR);
        $options = [
            'format' => $inputFormat,
            // A preferred label is required.
            'fill' => [
                'descriptor' => $settings->get('thesaurus_property_descriptor', 'skos:prefLabel'),
                'path' => $settings->get('thesaurus_property_path', ''),
                'ascendance' => $settings->get('thesaurus_property_ascendance', ''),
            ],
            'separator' => $separator,
            'clean' => $data['clean'] ?? [
                'trim_punctuation',
            ],
            'skip_first_line' => !empty($data['skip_first_line']),
        ];

        if (in_array($inputFormat, ['tab_offset_code_prepended', 'tab_offset_code_appended'])) {
            if (empty($data['codes'])) {
                $this->messenger()->addWarning(
                    'The input format is defined as containing codes, but no codes are defined.' // @translate
                );
            }
            $options['codes'] = $data['codes'];
            $options['position_code'] = $inputFormat === 'tab_offset_code_appended' ? 'appended' : 'prepended';
        }

        // TODO Check the file during validation inside the form.

        // Decompress gzipped files (for example a GEMET ".rdf.gz" export).
        $file = $this->decompressIfGzip($file);

        $fileCheck = $this->checkFile($file);
        if ($fileCheck === false) {
            $this->messenger()->addError(new PsrMessage(
                'Wrong media type ("{type}") for file.', // @translate
                ['type' => $file['type']]
            ));
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert']);
        } elseif (empty($file['size'])) {
            $this->messenger()->addError(
                'The file is empty.' // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert']);
        }

        $file = $fileCheck;
        $converted = $this->convertThesaurus($file['tmp_name'], $options, $file['type']);
        if (trim($converted) === '') {
            $this->messenger()->addError(
                'Unable to convert the file.' // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert']);
        }

        $name = pathinfo($file['name'], PATHINFO_FILENAME);

        if ($destination === 'thesaurus') {
            $options['create_customvocab'] = !empty($data['create_customvocab']);
            // Messages are included.
            $this->importThesaurus($file['tmp_name'], $file['name'], $options, $file['type']);
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'browse'], true);
        }

        if ($destination === 'customvocab') {
            $label = trim((string) ($data['customvocab_label'] ?? ''));
            $label = strlen($label) ? $label : (mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1));
            $this->createCustomVocabFromFlatList($converted, $label, $data['customvocab_format'] ?? 'path', $separator);
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'browse'], true);
        }

        // Default: preview the flat list, with shortcuts to both imports.
        $params = $this->params()->fromRoute();
        $params['action'] = 'flat';
        $params['result'] = $converted;
        $params['name'] = $name;
        return $this->forward()->dispatch(__CLASS__, $params);
    }

    /**
     * Process an action triggered from the preview screen (download, custom
     * vocabulary or thesaurus), using the previewed flat list as the source.
     */
    protected function processPreview(array $post)
    {
        $result = (string) $post['result'];
        $name = trim((string) ($post['name'] ?? ''));
        $name = strlen($name) ? $name : 'thesaurus';
        $separator = $this->settings()->get('thesaurus_separator', \Thesaurus\Module::SEPARATOR);

        if (isset($post['submit-download'])) {
            return $this->outputStringAsFile($result, $name . '.txt');
        }

        if (isset($post['submit-customvocab'])) {
            $label = trim((string) ($post['customvocab_label'] ?? ''));
            $label = strlen($label) ? $label : (mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1));
            $this->createCustomVocabFromFlatList($result, $label, $post['customvocab_format'] ?? 'path', $separator);
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'browse'], true);
        }

        if (isset($post['submit-thesaurus'])) {
            $this->importFlatListAsThesaurus($result, $name, $separator);
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'browse'], true);
        }

        return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert']);
    }

    public function flatAction()
    {
        $result = $this->params('result');
        if (!$result) {
            $this->messenger()->addWarning(
                'Convert first a file to get the flat list.' // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert']);
        }
        return new ViewModel([
            'result' => $this->stringToList($result, true),
            'resultString' => $result,
            'name' => (string) $this->params('name'),
            'hasCustomVocab' => class_exists('CustomVocab\Module', false),
        ]);
    }

    /**
     * Convert a flat list of full paths into a list of custom vocab terms.
     */
    protected function flatListToTerms(string $flatList, string $format, string $separator): array
    {
        $terms = [];
        foreach ($this->stringToList($flatList, true) as $line) {
            if ($format === 'label' || $format === 'indent') {
                $segments = array_map('trim', explode($separator, $line));
                $label = (string) end($segments);
                if ($label === '') {
                    continue;
                }
                $terms[] = $format === 'indent'
                    ? str_repeat("\t", count($segments) - 1) . $label
                    : $label;
            } else {
                $terms[] = $line;
            }
        }
        // Keep the order but drop exact duplicates.
        return array_values(array_unique($terms));
    }

    /**
     * Create a custom vocabulary of type "terms" from a flat list.
     */
    protected function createCustomVocabFromFlatList(string $flatList, string $label, string $format, string $separator): void
    {
        if (!class_exists('CustomVocab\Module', false)) {
            $this->messenger()->addError(
                'The module Custom Vocab is required to create a custom vocabulary.' // @translate
            );
            return;
        }

        $terms = $this->flatListToTerms($flatList, $format, $separator);
        if (!$terms) {
            $this->messenger()->addError(
                'There is no term to create the custom vocabulary.' // @translate
            );
            return;
        }

        try {
            $this->api()->create('custom_vocabs', [
                'o:label' => $label,
                'o:terms' => $terms,
            ]);
        } catch (\Exception $e) {
            $this->messenger()->addError(new PsrMessage(
                'Unable to create the custom vocabulary "{label}": {message}', // @translate
                ['label' => $label, 'message' => $e->getMessage()]
            ));
            return;
        }

        $this->messenger()->addSuccess(new PsrMessage(
            'The custom vocabulary "{label}" was created with {count} terms.', // @translate
            ['label' => $label, 'count' => count($terms)]
        ));
    }

    /**
     * Import a flat list of full paths as a thesaurus of items.
     *
     * The flat list is serialized as a tabulation offset list, then imported
     * through the standard engine.
     */
    protected function importFlatListAsThesaurus(string $flatList, string $name, string $separator): void
    {
        $lines = [];
        foreach ($this->stringToList($flatList, true) as $line) {
            $segments = explode($separator, $line);
            $label = trim((string) end($segments));
            if ($label === '') {
                continue;
            }
            $lines[] = str_repeat("\t", count($segments) - 1) . $label;
        }

        if (!$lines) {
            $this->messenger()->addError(
                'The list is empty.' // @translate
            );
            return;
        }

        $settings = $this->settings();
        $options = [
            'format' => 'tab_offset',
            'fill' => [
                'descriptor' => $settings->get('thesaurus_property_descriptor', 'skos:prefLabel'),
                'path' => $settings->get('thesaurus_property_path', ''),
                'ascendance' => $settings->get('thesaurus_property_ascendance', ''),
            ],
            'separator' => $separator,
            'clean' => [],
            'skip_first_line' => false,
        ];

        $this->dispatchCreateThesaurus($lines, mb_strtolower($name), $options);
    }

    /**
     * A job is required to avoid a partially updated tree.
     */
    protected function updateThesaurusStructure(ItemRepresentation $item, array $structure): self
    {
        // Only id and parent are useful, but remove is important too.
        $tree = [];
        foreach ($structure as $element) {
            if (!empty($element['id']) && (int) $element['id']) {
                $tree[(int) $element['id']] = [
                    'parent' => empty($element['parent']) || $element['parent'] === '#' ? null : (int) $element['parent'],
                    'remove' => !empty($element['data']['remove']),
                ];
            }
        }

        $dispatcher = $this->jobDispatcher();
        $args = [
            'scheme' => $item->id(),
            'structure' => $tree,
        ];

        // Use a foreground job: it's only some seconds.
        if (count($structure) < 100) {
            $job = $dispatcher->dispatch(\Thesaurus\Job\UpdateStructure::class, $args, $item->getServiceLocator()->get('Omeka\Job\DispatchStrategy\Synchronous'));
            $message = 'Structure saved and reindexed ({link}job #{job_id}{link_end}, {link_log}logs{link_end}).'; // @translate
        } else {
            // TODO If background job, check if the thesaurus is not restructurating or indexing before to display its structure, else errors may occur.
            $job = $dispatcher->dispatch(\Thesaurus\Job\UpdateStructure::class, $args);
            $message = 'Indexing structure in background. Do not display structure while indexing, else errors may occur ({link}job #{job_id}{link_end}, {link_log}logs{link_end}).'; // @translate
        }

        $message = new PsrMessage(
            $message,
            [
                'link' => sprintf('<a href="%s">', htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', $this->url()->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : sprintf('<a href="%1$s" target="_blank">', $this->url()->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
        return $this;
    }

    /**
     * Convert a flat list into a flat thesaurus.
     */
    protected function convertThesaurus(
        string $filepath,
        array $options,
        ?string $mediaType = 'text/plain'
    ): string {
        $inputFormat = $options['format'] ?? '';
        if ($inputFormat === 'skos') {
            return $this->convertThesaurusSkos($filepath, $options, $mediaType);
        }
        $text = file_get_contents($filepath);
        // TODO The "@" avoids the deprecation notice. Replace by html_entity_decode/htmlentities.
        $text = mb_encode_numericentity($text, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $lines = $this->stringToList($text, false);
        if (count($lines) && !empty($options['skip_first_line'])) {
            unset($lines[0]);
        }
        if ($inputFormat === 'tab_offset') {
            return $this->convertThesaurusTabOffset($lines, $options);
        } elseif ($inputFormat === 'tab_offset_code_prepended' || $inputFormat === 'tab_offset_code_appended') {
            return $this->convertThesaurusTabOffsetCodes($lines, $options);
        } elseif ($inputFormat === 'structure_label') {
            return $this->convertThesaurusStructureLabel($lines, $options);
        }
        return '';
    }

    /**
     * Convert a structured list into a flat thesaurus from format "tab offset".
     */
    protected function convertThesaurusTabOffset(array $lines, array $options): string
    {
        $output = '';
        $separator = $options['separator'] ?? \Thesaurus\Module::SEPARATOR;

        $levels = [];

        foreach ($lines as $line) {
            $descriptor = trim($line);
            if (!strlen($descriptor)) {
                continue;
            }
            // Replace entities first to avoid to break html entities.
            $descriptor = trim((string) mb_decode_numericentity($descriptor, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));
            $descriptor = $this->trimAndCleanString($descriptor, $options['clean']);
            if (!strlen($descriptor)) {
                continue;
            }
            $line = rtrim($line);
            $level = strrpos($line, "\t");
            $level = $level === false ? 0 : ++$level;
            $levels[$level] = $descriptor;
            $row = '';
            for ($i = 0; $i < $level; ++$i) {
                $row .= $levels[$i] ?? '';
                $row .= $separator;
            }
            $row .= $descriptor;
            $levels[$level] = $descriptor;
            $output .= $row . "\n";
        }
        return $output;
    }

    /**
     * Convert a structured list into a flat thesaurus from format tabs/codes.
     *
     * So mainly for checks.
     *
     * Here, there are three descriptors and the two "used for" are lost.
     *
     * Europa
     * UF Europe
     *      France
     *      United Kingdom
     *      UF England
     *
     * @uses self::convertThesaurusTabOffset()
     */
    protected function convertThesaurusTabOffsetCodes(array $lines, array $options): string
    {
        $isCodeAppended = ($options['position_code'] ?? null) === 'appended';

        $valueCodes = $options['codes'] ?? [];
        $newLines = [];
        foreach ($lines as $line) {
            $descriptor = trim($line);
            if (!strlen($descriptor)) {
                continue;
            }
            // Replace entities first to avoid to break html entities.
            $descriptor = trim((string) mb_decode_numericentity($descriptor, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));
            if ($isCodeAppended) {
                $codeToCheck = mb_strpos($descriptor, ' ') === false? null : trim(mb_strrchr($descriptor, ' '));
            } else {
                $codeToCheck = mb_strpos($descriptor, ' ') === false? null : strtok(trim($descriptor), ' ');
            }
            if (isset($valueCodes[$codeToCheck])) {
                continue;
            }
            $descriptor = $this->trimAndCleanString($descriptor, $options['clean']);
            if (!strlen($descriptor)) {
                continue;
            }
            $newLines[] = $line;
        }
        return $this->convertThesaurusTabOffset($newLines, $options);
    }

    /**
     * Convert a flat list into a flat thesaurus from format "structure label".
     *
     * The input should be ordered and logical.
     *
     * 01          Europe
     * 01-01       France
     * 01-01-01    Paris
     * 01-02       United Kingdom
     * 01-02-01    England
     * 01-02-01-01 London
     * 02          Asia
     * 02-01       Japan
     * 02-01-01    Tokyo
     */
    protected function convertThesaurusStructureLabel(array $lines, array $options): string
    {
        $output = '';
        $separator = $options['separator'] ?? \Thesaurus\Module::SEPARATOR;

        $sep = '-';

        $trimPunctuation = in_array('trim_punctuation', $options['clean']);

        // First, prepare a key-value array. The key should be a string.
        $input = [];
        foreach ($lines as $line) {
            [$structure, $descriptor] = array_map('trim', (explode(' ', $line . ' ', 2)));
            // Replace entities first to avoid to break html entities.
            $structure = trim((string) mb_decode_numericentity($structure, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));
            $descriptor = trim((string) mb_decode_numericentity($descriptor, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));
            if ($trimPunctuation) {
                $structure = trim($structure, \Thesaurus\Job\CreateThesaurus::TRIM_PUNCTUATION);
            }
            $descriptor = $this->trimAndCleanString($descriptor, $options['clean']);
            if (!strlen($descriptor)) {
                continue;
            }
            $input[(string) $structure] = $descriptor;
        }
        $input = array_filter($input);

        // Second, prepare each row.
        foreach ($input as $structure => $descriptor) {
            $row = '';
            // Get parent structure name (cut last part, that is current one).
            $structureArray = explode($sep, (string) $structure);
            array_pop($structureArray);
            $structureArray = $structureArray ?: [];
            foreach (array_keys($structureArray) as $key) {
                $parentStructureName = implode($sep, array_slice($structureArray, 0, $key + 1));
                $row .= $input[$parentStructureName] ?? '';
                $row .= $separator;
            }
            $row .= $descriptor;
            $output .= $row . "\n";
        }

        return $output;
    }

    /**
     * Convert a standard SKOS file into a flat thesaurus.
     *
     * @todo Import altLabel, scopeNote and notation from SKOS (currently only the hierarchy of prefLabel is kept).
     */
    protected function convertThesaurusSkos(
        string $filepath,
        array $options,
        ?string $mediaType = null
    ): string {
        $tree = $this->parseSkosTree($filepath, $this->skosRdfFormat($mediaType));
        if (!$tree) {
            return '';
        }

        $separator = $options['separator'] ?? \Thesaurus\Module::SEPARATOR;
        $clean = $options['clean'] ?? [];

        $output = '';
        foreach ($tree as $element) {
            $label = $this->trimAndCleanString($element['label'], $clean);
            if (!strlen($label)) {
                continue;
            }
            $path = array_map(fn ($ancestor) => $this->trimAndCleanString($ancestor, $clean), $element['path']);
            $output .= implode($separator, array_merge($path, [$label])) . "\n";
        }
        return $output;
    }

    /**
     * Parse a SKOS file into an ordered and flattened tree of concepts.
     *
     * Each element has the keys "level" (depth, starting at 0), "label" (the
     * preferred label) and "path" (the list of ancestor labels). The hierarchy
     * is built from skos:narrower and skos:broader; roots are the concepts that
     * are not a child of another one.
     *
     * @return array<array{level: int, label: string, path: string[]}>
     */
    protected function parseSkosTree(string $filepath, ?string $format = null): array
    {
        \EasyRdf\RdfNamespace::set('skos', 'http://www.w3.org/2004/02/skos/core#');

        $graph = new \EasyRdf\Graph();
        try {
            $graph->parseFile($filepath, $format);
        } catch (\Exception $e) {
            return [];
        }

        /** @var \EasyRdf\Resource[] $concepts */
        $concepts = $graph->allOfType('skos:Concept');
        if (!$concepts) {
            return [];
        }

        $byUri = [];
        $labels = [];
        foreach ($concepts as $concept) {
            $uri = $concept->getUri();
            $byUri[$uri] = $concept;
            $literal = $concept->getLiteral('skos:prefLabel');
            $labels[$uri] = $literal ? trim((string) $literal->getValue()) : '';
        }

        // Build the parent → children relations from broader and narrower.
        $children = [];
        $isChild = [];
        foreach ($byUri as $uri => $concept) {
            foreach ($concept->all('skos:narrower') as $narrower) {
                $childUri = $narrower->getUri();
                if (isset($byUri[$childUri])) {
                    $children[$uri][$childUri] = $childUri;
                    $isChild[$childUri] = true;
                }
            }
            foreach ($concept->all('skos:broader') as $broader) {
                $parentUri = $broader->getUri();
                if (isset($byUri[$parentUri])) {
                    $children[$parentUri][$uri] = $uri;
                    $isChild[$uri] = true;
                }
            }
        }

        // Roots are the concepts that are not a child of another concept.
        $roots = array_keys(array_diff_key($byUri, $isChild));

        // Sort sibling concepts by label then uri for a deterministic output.
        $sort = function (array $uris) use ($labels): array {
            usort($uris, fn ($a, $b) => [$labels[$a], $a] <=> [$labels[$b], $b]);
            return $uris;
        };

        $tree = [];
        $visited = [];
        $walk = function (array $uris, array $path) use (&$walk, &$tree, &$visited, $children, $labels, $sort): void {
            foreach ($sort($uris) as $uri) {
                if (isset($visited[$uri])) {
                    continue;
                }
                $visited[$uri] = true;
                $label = $labels[$uri];
                // Keep descendants at the same level when a concept has no
                // label.
                $childPath = $label === '' ? $path : array_merge($path, [$label]);
                if ($label !== '') {
                    $tree[] = ['level' => count($path), 'label' => $label, 'path' => $path];
                }
                if (!empty($children[$uri])) {
                    $walk(array_values($children[$uri]), $childPath);
                }
            }
        };
        $walk($roots, []);

        // Append concepts unreachable from roots (e.g. broken by a cycle).
        $remaining = array_keys(array_diff_key($byUri, $visited));
        if ($remaining) {
            $walk($remaining, []);
        }

        return $tree;
    }

    /**
     * Get the EasyRdf format name from a media type, or null to let it guess.
     */
    protected function skosRdfFormat(?string $mediaType): ?string
    {
        $map = [
            'application/rdf+xml' => 'rdfxml',
            'application/xml' => 'rdfxml',
            'text/xml' => 'rdfxml',
            'text/turtle' => 'turtle',
            'application/x-turtle' => 'turtle',
            'text/n3' => 'n3',
            'application/n-triples' => 'ntriples',
            'application/ld+json' => 'jsonld',
            'application/json' => 'jsonld',
        ];
        return $map[$mediaType] ?? null;
    }

    /**
     * Convert a tree as a thesaurus.
     */
    protected function importThesaurus(
        string $filepath,
        string $filename,
        array $options,
        ?string $mediaType = 'text/plain'
    ): void {
        if (($options['format'] ?? '') === 'skos') {
            // Reuse the core engine: serialize the SKOS tree as a tabulation
            // offset list, then import it as a standard "tab_offset" file.
            $lines = [];
            foreach ($this->parseSkosTree($filepath, $this->skosRdfFormat($mediaType)) as $element) {
                $lines[] = str_repeat("\t", $element['level']) . $element['label'];
            }
            $options['format'] = 'tab_offset';
        } else {
            $text = file_get_contents($filepath);
            $text = mb_encode_numericentity($text, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
            $lines = $this->stringToList($text, false);
        }

        if (!$lines) {
            $this->messenger()->addError(
                'The file is empty.' // @translate
            );
            return;
        }

        $this->dispatchCreateThesaurus($lines, mb_strtolower(pathinfo($filename, PATHINFO_FILENAME)), $options);
    }

    /**
     * Dispatch the job that creates a thesaurus from a list of input lines.
     */
    protected function dispatchCreateThesaurus(array $lines, string $name, array $options): void
    {
        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        $dispatcher = $this->jobDispatcher();

        $params = [
            'name' => $name,
            'input' => $lines,
        ] + $options;

        // Use synchronous dispatcher when the thesaurus is small.
        $small = count($lines) <= 50;
        $strategy = $small
            ? $this->api()->read('vocabularies', 1)->getContent()->getServiceLocator()
                ->get(\Omeka\Job\DispatchStrategy\Synchronous::class)
            : null;
        $job = $dispatcher->dispatch(\Thesaurus\Job\CreateThesaurus::class, $params, $strategy);

        if ($small) {
            $this->messenger()->addSuccess(new PsrMessage(
                'The thesaurus "{title}" is created.', // @translate
                ['title' => mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1)]
            ));
            return;
        }

        $message = new PsrMessage(
            'Creation of thesaurus "{title}" with {total} lines started ({link}job #{job_id}{link_end}, {link_log}logs{link_end})', // @translate
            [
                'title' => mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1),
                'total' => count($lines),
                'link' => sprintf('<a href="%s">', htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', $this->url()->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : sprintf('<a href="%1$s" target="_blank">', $this->url()->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
    }

    protected function processUpdateConcepts(ItemRepresentation $item, string $mode = 'replace'): void
    {
        $settings = $this->settings();

        $args = [
            'scheme' => (int) $item->id(),
            // A preferred label is required.
            'fill' => [
                // Pass the descriptor to job for check, but not used.
                'descriptor' => $settings->get('thesaurus_property_descriptor', 'skos:prefLabel'),
                'path' => $settings->get('thesaurus_property_path', ''),
                'ascendance' => $settings->get('thesaurus_property_ascendance', ''),
            ],
            'separator' => $settings->get('thesaurus_separator', \Thesaurus\Module::SEPARATOR),
            'mode' => $mode,
        ];

        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch(\Thesaurus\Job\UpdateConcepts::class, $args);
        $message = new PsrMessage(
            'Updating concepts in background ({link}job #{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link' => sprintf('<a href="%s">', htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => class_exists('Log\Module', false)
                    ? sprintf('<a href="%1$s">', $this->url()->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]))
                    : sprintf('<a href="%1$s" target="_blank">', $this->url()->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()])),
            ]
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
    }

    /**
     * Check the file, according to its media type.
     *
     * @todo Use the class TempFile before.
     *
     * @param array $fileData File data from a post ($_FILES).
     * @return array|bool
     */
    protected function checkFile(array $fileData)
    {
        if (empty($fileData) || empty($fileData['tmp_name'])) {
            return false;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($fileData['tmp_name']);
        $extension = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
        $fileData['extension'] = $extension;

        // Manage an exception for a very common format, undetected by fileinfo.
        if ($mediaType === 'text/plain') {
            $extensions = [
                'txt' => 'text/plain',
                'csv' => 'text/csv',
                'tab' => 'text/tab-separated-values',
                'tsv' => 'text/tab-separated-values',
            ];
            if (isset($extensions[$extension])) {
                $mediaType = $extensions[$extension];
                $fileData['type'] = $mediaType;
            }
        }

        // SKOS files are unreliably detected by fileinfo (rdfxml as xml,
        // turtle/n-triples as text/plain, json-ld as json), so the media type
        // is forced from the extension to let EasyRdf parse them.
        $skosExtensions = [
            'rdf' => 'application/rdf+xml',
            'rdfs' => 'application/rdf+xml',
            'xml' => 'application/rdf+xml',
            'ttl' => 'text/turtle',
            'n3' => 'text/n3',
            'nt' => 'application/n-triples',
            'jsonld' => 'application/ld+json',
        ];
        if (isset($skosExtensions[$extension])) {
            $mediaType = $skosExtensions[$extension];
            $fileData['type'] = $mediaType;
        }

        $supporteds = [
            // 'application/vnd.oasis.opendocument.spreadsheet' => true,
            'text/plain' => true,
            'text/tab-separated-values' => true,
            'application/rdf+xml' => true,
            // A remote SKOS file without extension is often detected as xml or
            // json by fileinfo (in particular for an Opentheso export).
            'application/xml' => true,
            'text/xml' => true,
            'text/turtle' => true,
            'text/n3' => true,
            'application/n-triples' => true,
            'application/ld+json' => true,
            'application/json' => true,
        ];
        if (!isset($supporteds[$mediaType])) {
            return false;
        }

        $fileData['type'] = $mediaType;

        return $fileData;
    }

    /**
     * Fetch a remote file into a temporary file, similar to an uploaded file.
     *
     * @return array|null File data (tmp_name, name, type, size, error), or null.
     */
    protected function fetchUrl(string $url): ?array
    {
        $services = $this->getEvent()->getApplication()->getServiceManager();
        /** @var \Laminas\Http\Client $client */
        $client = $services->get('Omeka\HttpClient');
        $client
            ->reset()
            ->setUri($url)
            ->setMethod('GET')
            ->setOptions(['timeout' => 30, 'maxredirects' => 5])
            ->setHeaders([
                'Accept' => 'application/rdf+xml, text/turtle, application/ld+json, application/n-triples, text/plain, */*',
            ]);

        try {
            $response = $client->send();
        } catch (\Exception $e) {
            $this->messenger()->addError(new PsrMessage(
                'Unable to fetch the URL: {message}', // @translate
                ['message' => $e->getMessage()]
            ));
            return null;
        }

        if (!$response->isSuccess()) {
            $this->messenger()->addError(new PsrMessage(
                'Unable to fetch the URL (status {status}).', // @translate
                ['status' => $response->getStatusCode()]
            ));
            return null;
        }

        $content = $response->getBody();
        if (trim((string) $content) === '') {
            $this->messenger()->addError(
                'The fetched URL is empty.' // @translate
            );
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'omk_theso_');
        file_put_contents($temp, $content);

        $name = basename((string) parse_url($url, PHP_URL_PATH));
        $name = strlen($name) ? $name : 'thesaurus';

        return [
            'tmp_name' => $temp,
            'name' => $name,
            'type' => '',
            'size' => strlen($content),
            'error' => \UPLOAD_ERR_OK,
        ];
    }

    /**
     * Decompress a gzipped file into a new temporary file.
     *
     * The file is detected by its magic bytes, so the extension is not
     * required. The ".gz" suffix is removed from the name, so the real
     * extension can be detected. The original file is returned unchanged when
     * it is not gzipped or when the decompression fails.
     */
    protected function decompressIfGzip(array $file): array
    {
        if (empty($file['tmp_name'])) {
            return $file;
        }

        $handle = fopen($file['tmp_name'], 'rb');
        if (!$handle) {
            return $file;
        }
        $magic = fread($handle, 2);
        fclose($handle);
        if ($magic !== "\x1f\x8b") {
            return $file;
        }

        // The zlib extension is bundled with php but not guaranteed (and not
        // required by Omeka), so the gzipped file is left as is when missing.
        if (!function_exists('gzopen')) {
            $this->messenger()->addError(
                'The php extension "zlib" is required to import a gzipped file.' // @translate
            );
            return $file;
        }

        $gz = gzopen($file['tmp_name'], 'rb');
        if (!$gz) {
            return $file;
        }
        $temp = tempnam(sys_get_temp_dir(), 'omk_theso_');
        $out = fopen($temp, 'wb');
        if (!$out) {
            gzclose($gz);
            return $file;
        }
        $size = 0;
        while (!gzeof($gz)) {
            $chunk = gzread($gz, 8192);
            if ($chunk === false) {
                break;
            }
            $size += fwrite($out, $chunk);
        }
        gzclose($gz);
        fclose($out);

        $name = (string) ($file['name'] ?? '');
        if (substr(strtolower($name), -3) === '.gz') {
            $name = substr($name, 0, -3);
        }

        $file['tmp_name'] = $temp;
        $file['name'] = strlen($name) ? $name : 'thesaurus';
        $file['type'] = '';
        $file['size'] = $size;

        return $file;
    }

    /**
     * Output a string as file.
     *
     * @param string $text
     * @param string $filename
     * @param string $mediaType
     * @param string $mode "inline" or "attachment" (default).
     * @return \Laminas\Stdlib\ResponseInterface
     */
    protected function outputStringAsFile($text, $filename = 'output.txt', $mediaType = 'text/plain', $mode = 'attachment')
    {
        $fileSize = strlen($text);

        // Write HTTP headers
        /** @var \Laminas\Stdlib\ResponseInterface $response */
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers
            ->addHeaderLine('Content-type: ' . $mediaType)
            ->addHeaderLine('Content-Disposition: ' . $mode . '; filename="' . $filename . '"')
            ->addHeaderLine('Content-Transfer-Encoding', 'binary')
            ->addHeaderLine('Content-length: ' . $fileSize)
            ->addHeaderLine('Cache-control: private')
            ->addHeaderLine('Content-Description: ' . 'File Transfer');

        // Write file content.
        $response->setContent($text);

        // Return Response to avoid default view rendering
        return $response;
    }

    /**
     * Trim and clean string according to options.
     *
     * @todo Factorize the function trimAndCleanString() of ThesaurusController and CreateThesaurus;
     */
    protected function trimAndCleanString($string, array $params): string
    {
        $string = trim((string) $string);
        // Normalize to Unicode NFC so identical-looking strings are identical
        // byte-wise (avoids duplicates, failed lookups and broken truncation
        // with decomposed input, typically from macOS or some SKOS/CSV
        // exports).
        $normalized = \Normalizer::normalize($string, \Normalizer::FORM_C);
        if ($normalized !== false) {
            $string = $normalized;
        }
        if (in_array('trim_punctuation', $params)) {
            $string = trim($string, \Thesaurus\Job\CreateThesaurus::TRIM_PUNCTUATION);
        }
        if (in_array('apostrophe', $params)) {
            $string = strtr($string, ["'" => '’']);
        }
        if (in_array('single_quote', $params)) {
            $string = strtr($string, ['’' => "'"]);
        }
        if (in_array('lowercase', $params)) {
            $string = mb_strtolower($string);
        }
        if (in_array('ucfirst', $params)) {
            $string = mb_strtoupper(mb_substr($string, 0, 1)) . mb_strtolower(mb_substr($string, 1));
        }
        if (in_array('ucwords', $params)) {
            $string = mb_convert_case($string, MB_CASE_TITLE, 'UTF-8');
        }
        if (in_array('uppercase', $params)) {
            $string = mb_strtoupper($string);
        }
        return $string;
    }

    /**
     * Get each line of a string separately.
     */
    protected function stringToList($string, bool $trim = false): array
    {
        return $trim
            ? array_filter(array_map('trim', explode("\n", $this->fixEndOfLine($string))), 'strlen')
            : array_filter(explode("\n", $this->fixEndOfLine($string)), 'strlen');
    }

    /**
     * Clean the text area from end of lines.
     *
     * This method fixes Windows and Apple copy/paste from a textarea input.
     */
    protected function fixEndOfLine($string): string
    {
        return strtr((string) $string, ["\r\n" => "\n", "\n\r" => "\n", "\r" => "\n"]);
    }
}
