<?php declare(strict_types=1);

namespace Thesaurus\Controller\Admin;

use finfo;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Controller\Admin\ItemController;
use Omeka\Mvc\Exception\NotFoundException;
use Omeka\Mvc\Exception\RuntimeException;
use Omeka\Stdlib\Message;
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
            $this->messenger()->addWarning('The thesaurus has no item set with class "skos:Collection" or "skos:OrderedCollection".'); // @translate
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
                $scheme = $api->searchOne('items', ['id' => $id])->getContent();
                if (!$scheme) {
                    $message = new Message('The item #%d is not available.', $id); // @translate
                    $this->messenger()->addError($message);
                } else {
                    /** @var \Thesaurus\Mvc\Controller\Plugin\Thesaurus $thesaurus */
                    $thesaurus = $this->thesaurus($scheme);
                    if (!$thesaurus->isSkos()) {
                        $message = new Message('The item #%d does not belong to a thesaurus.', $id); // @translate
                        $this->messenger()->addError($message);
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
            throw new RuntimeException(new Message(
                'Item #%d is not a skos scheme and is not a thesaurus.', // @translate
                $id
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
            $message = 'The item #%d does not belong to a thesaurus.'; // @translate
            $message = new Message(
                $message,
                $item->id()
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
        $job = $dispatcher->dispatch(\Thesaurus\Job\Indexing::class, $args);
        $message = new \Omeka\Stdlib\Message(
            'Indexing concepts in background (%1$sjob #%2$d%3$s, %4$slogs%3$s).', // @translate
            sprintf(
                '<a href="%s">',
                htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
            ),
            $job->getId(),
            '</a>',
            sprintf('<a href="%1$s">', class_exists('Log\Entity\Log') ? $this->url()->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]) :  $this->url()->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))
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
                        $message = 'You may need to reload %1$sthis page%2$s a second time to clean indexation of top concepts.'; // @translate
                        $message = new Message(
                            $message,
                            sprintf('<a href="%s">',
                                htmlspecialchars($this->url()->fromRoute('admin/thesaurus/id', [], true))
                            ),
                            '</a>'
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
                sprintf('Unallowed request.') // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'browse'], true);
        }

        $files = $request->getFiles()->toArray();
        if (empty($files)) {
            $this->messenger()->addError(
                sprintf('Missing file.') // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert'], true);
        }

        /** @var \Thesaurus\Form\ConvertForm $form */
        $post = $this->params()->fromPost() + $files;

        $form = $this->getForm(ConvertForm::class);
        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addError(
                sprintf('Wrong request for file.') // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert'], true);
        }

        $settings = $this->settings();

        $data = $form->getData();
        $outputType = $data['output'] ?? 'text';
        $options = [
            'format' => $data['format'] ?? 'tab_offset',
            // A preferred label is required.
            'fill' => [
                'descriptor' => $settings->get('thesaurus_property_descriptor', 'skos:prefLabel'),
                'path' => $settings->get('thesaurus_property_path', ''),
                'ascendance' => $settings->get('thesaurus_property_ascendance', ''),
            ],
            'separator' => $settings->get('thesaurus_separator', \Thesaurus\Module::SEPARATOR),
            'clean' => $data['clean'] ?? [
                'trim_punctuation',
            ],
        ];

        // TODO Check the file during validation inside the form.

        $file = $files['file'];
        $fileCheck = $this->checkFile($file);
        if (!empty($file['error'])) {
            $this->messenger()->addError(
                sprintf('An error occurred when uploading the file.') // @translate
            );
        } elseif ($fileCheck === false) {
            $this->messenger()->addError(new Message(
                'Wrong media type ("%s") for file.', // @translate
                $file['type']
            ));
        } elseif (empty($file['size'])) {
            $this->messenger()->addError(
                sprintf('The file is empty.') // @translate
            );
        } else {
            $file = $fileCheck;
            $converted = $this->convertThesaurus($file['tmp_name'], $options, $file['type']);
            if (empty($converted)) {
                $this->messenger()->addError(
                    sprintf('Unable to convert the file.') // @translate
                );
            } else {
                if ($outputType === 'thesaurus') {
                    // Message are included.
                    $this->importThesaurus($file['tmp_name'], $file['name'], $options, $file['type']);
                    return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'browse'], true);
                }
                if ($outputType === 'file') {
                    $this->messenger()->addSuccess(
                        'The file is successfully converted.' // @translate
                    );
                    $filename = pathinfo($file['name'], PATHINFO_FILENAME)
                        . '.output'
                        . (strlen($file['extension']) ? '.' . $file['extension'] : '');
                    return $this->outputStringAsFile($converted, $filename);
                }
                $this->messenger()->addSuccess(
                    'The file is successfully converted. You can now copy-paste below data into a custom vocab.' // @translate
                );
                // return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'flat'], ['query' => ['file' => pathinfo($file['name'], PATHINFO_FILENAME)]], true);
                $params = $this->params()->fromRoute();
                $params['action'] = 'flat';
                $params['result'] = $converted;
                return $this->forward()->dispatch(__CLASS__, $params);
            }
        }

        return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert']);
    }

    public function flatAction()
    {
        $result = $this->params('result');
        if (!$result) {
            $this->messenger()->addWarning(
                'Convert first a file to get the flat thesaurus.' // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert']);
        }
        return new ViewModel([
            'result' => $this->stringToList($result, true),
        ]);
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
            $message = 'Structure saved and reindexed (%1$sjob #%2$d%3$s, %4$slogs%3$s).'; // @translate
        } else {
            // TODO If background job, check if the thesaurus is not restructurating or indexing before to display its structure, else errors may occur.
            $job = $dispatcher->dispatch(\Thesaurus\Job\UpdateStructure::class, $args);
            $message = 'Indexing structure in background. Do not display structure while indexing, else errors may occur (%1$sjob #%2$d%3$s, %4$slogs%3$s).'; // @translate
        }

        $message = new Message(
            $message,
            sprintf('<a href="%s">',
                htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
            ),
            $job->getId(),
            '</a>',
            sprintf('<a href="%1$s">', class_exists('Log\Entity\Log') ? $this->url()->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]) :  $this->url()->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))
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
        $text = file_get_contents($filepath);
        $text = mb_convert_encoding($text, 'HTML-ENTITIES', 'UTF-8');
        $lines = $this->stringToList($text, false);
        $format = $options['format'] ?? '';
        if ($format === 'tab_offset') {
            return $this->convertThesaurusTabOffset($lines, $options);
        } elseif ($format === 'structure_label') {
            return $this->convertThesaurusStructureLabel($lines, $options);
        }
        return '';
    }

    /**
     * Convert a flat list into a flat thesaurus from format "tab offset".
     */
    protected function convertThesaurusTabOffset(array $lines, array $options): string
    {
        $output = '';
        $separator = $options['separator'] ?? \Thesaurus\Module::SEPARATOR;

        $levels = [];

        $trimPunctuation = in_array('trim_punctuation', $options['clean']);

        foreach ($lines as $line) {
            $descriptor = trim($line);
            // Replace entities first to avoid to break html entities.
            $descriptor = mb_convert_encoding($descriptor, 'UTF-8', 'HTML-ENTITIES');
            if ($trimPunctuation) {
                $descriptor = trim($descriptor, \Thesaurus\Job\CreateThesaurus::TRIM_PUNCTUATION);
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
            $structure = mb_convert_encoding($structure, 'UTF-8', 'HTML-ENTITIES');
            $descriptor = mb_convert_encoding($descriptor, 'UTF-8', 'HTML-ENTITIES');
            if ($trimPunctuation) {
                $structure = trim($structure, \Thesaurus\Job\CreateThesaurus::TRIM_PUNCTUATION);
                $descriptor = trim($descriptor, \Thesaurus\Job\CreateThesaurus::TRIM_PUNCTUATION);
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
     * Convert a tree as a thesaurus.
     */
    protected function importThesaurus(
        string $filepath,
        string $filename,
        array $options,
        ?string $mediaType = 'text/plain'
    ): void {
        $text = file_get_contents($filepath);
        $text = mb_convert_encoding($text, 'HTML-ENTITIES', 'UTF-8');

        $lines = $this->stringToList($text, false);
        if (!$lines) {
            $message = new Message(
                'The file is empty.' // @translate
            );
            $this->messenger()->addError($message);
            return;
        }

        /** @var \Omeka\Mvc\Controller\Plugin\JobDispatcher $dispatcher */
        $dispatcher = $this->jobDispatcher();

        $name = mb_strtolower(pathinfo($filename, PATHINFO_FILENAME));
        $params = [
            'name' => $name,
            'input' => $lines,
        ] + $options;

        $small = count($lines) <= 100;
        if ($small) {
            $dispatcher->dispatch(\Thesaurus\Job\CreateThesaurus::class, $params, $this->api()->read('vocabularies', 1)->getContent()->getServiceLocator()->get('Omeka\Job\DispatchStrategy\Synchronous'));
            $message = new Message(
                'The thesaurus "%s" is created.', // @translate
                ucfirst($name)
            );
            $this->messenger()->addSuccess($message);
            return;
        }

        $job = $dispatcher->dispatch(\Thesaurus\Job\CreateThesaurus::class, $params);
        $urlHelper = $this->viewHelpers()->get('url');
        $message = new Message(
            'Creation of thesaurus "%1$s" with %2$d concepts started in job %3$s#%4$d%5$s (%6$slogs%5$s)', // @translate
            ucfirst($name),
            count($lines),
            sprintf('<a href="%1$s">', $urlHelper('admin/id', ['controller' => 'job', 'id' => $job->getId()])),
            $job->getId(),
            '</a>',
            sprintf('<a href="%1$s">', class_exists('Log\Stdlib\PsrMessage') ? $urlHelper('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]) :  $urlHelper('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))
        );
        $message->setEscapeHtml(false);
        $this->messenger()->addSuccess($message);
    }

    protected function processUpdateConcepts(ItemRepresentation $item, string $mode = 'replace')
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
        $message = new \Omeka\Stdlib\Message(
            'Updating concepts in background (%1$sjob #%2$d%3$s, %4$slogs%3$s).', // @translate
            sprintf(
                '<a href="%s">',
                htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                ),
            $job->getId(),
            '</a>',
            sprintf('<a href="%1$s">', class_exists('Log\Entity\Log') ? $this->url()->fromRoute('admin/default', ['controller' => 'log'], ['query' => ['job_id' => $job->getId()]]) :  $this->url()->fromRoute('admin/id', ['controller' => 'job', 'action' => 'log', 'id' => $job->getId()]))
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

        $supporteds = [
            // 'application/vnd.oasis.opendocument.spreadsheet' => true,
            'text/plain' => true,
            'text/tab-separated-values' => true,
        ];
        if (!isset($supporteds[$mediaType])) {
            return false;
        }

        return $fileData;
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
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], (string) $string);
    }
}
