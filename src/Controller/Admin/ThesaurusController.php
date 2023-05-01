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
use Thesaurus\Form\ConvertForm;

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
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert']);
        }

        $files = $request->getFiles()->toArray();
        if (empty($files)) {
            $this->messenger()->addError(
                sprintf('Missing file.') // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert']);
        }

        /** @var \Thesaurus\Form\ConvertForm $form */
        $post = $this->params()->fromPost() + $files;
        $form = $this->getForm(ConvertForm::class);
        $form->setData($post);
        if (!$form->isValid()) {
            $this->messenger()->addError(
                sprintf('Wrong request for file.') // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'convert']);
        }

        $data = $form->getData();
        $outputType = ($data['output'] ?? 'text') === 'file' ? 'file' : 'text';

        // TODO Check the file during validation inside the form.

        $file = $files['thesaurus'];
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
            $converted = $this->convertThesaurus($file['tmp_name'], $file['type']);
            if (empty($converted)) {
                $this->messenger()->addError(
                    sprintf('Unable to convert the file.') // @translate
                );
            } else {
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
                // return $this->redirect()->toRoute('admin/thesaurus/default', ['action' => 'flat'], ['query' => ['file' => pathinfo($file['name'], PATHINFO_FILENAME)]]);
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
    protected function convertThesaurus(string $filepath, ?string $mediaType = 'text/plain'): string
    {
        $output = '';
        $separator = ' :: ';

        $text = file_get_contents($filepath);
        $lines = $this->stringToList($text, false);
        $levels = [];
        foreach ($lines as $line) {
            $term = ltrim($line);
            $level = strrpos($line, "\t");
            $level = $level === false ? 0 : ++$level;
            $levels[$level] = $term;
            $row = '';
            for ($i = 0; $i < $level; ++$i) {
                $row .= $levels[$i] ?? '';
                $row .= $separator;
            }
            $row .= $term;
            $levels[$level] = $term;
            $output .= $row . "\n";
        }

        return $output;
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
