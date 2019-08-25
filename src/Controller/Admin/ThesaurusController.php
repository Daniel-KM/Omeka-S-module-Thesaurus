<?php
namespace Thesaurus\Controller\Admin;

use finfo;
use Thesaurus\Form\ConvertForm;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class ThesaurusController extends AbstractActionController
{
    public function indexAction()
    {
        return $this->redirect()->toRoute('admin/thesaurus', ['action' => 'convert']);
    }

    public function convertAction()
    {
        $view = new ViewModel;

        $form = $this->getForm(ConvertForm::class);
        $form->setAttribute('action', $this->url()->fromRoute('admin/thesaurus', ['action' => 'upload']));
        $form->init();

        $view->form = $form;
        return $view;
    }

    public function uploadAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            $this->messenger()->addError(
                sprintf('Unallowed request.') // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus', ['action' => 'convert']);
        }

        $files = $request->getFiles()->toArray();
        if (empty($files)) {
            $this->messenger()->addError(
                sprintf('Missing file.') // @translate
            );
            return $this->redirect()->toRoute('admin/thesaurus', ['action' => 'convert']);
        }

        $post = $this->params()->fromPost();
        $form = $this->getForm(ConvertForm::class);
        $form->setData($post);
        // TODO Important: Check csrf, even other checks are enough.
        // if (!$form->isValid()) {
        //     $this->messenger()->addError(
        //         sprintf('Wrong request for file.') // @translate
        //     );
        //     return $this->redirect()->toRoute('admin/spreadsheet-sync');
        // }

        // TODO Check the file during validation inside the form.

        $file = $files['thesaurus'];
        $fileCheck = $this->checkFile($file);
        if (!empty($file['error'])) {
            $this->messenger()->addError(
                sprintf('An error occurred when uploading the file.') // @translate
            );
        } elseif ($fileCheck === false) {
            $this->messenger()->addError(
                sprintf('Wrong media type ("%s") for file.', // @translate
                    $file['type'])
            );
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
                $this->messenger()->addSuccess(
                    'The file is successfully converted.'
                );
                $filename = pathinfo($file['name'], PATHINFO_FILENAME)
                    . '.output'
                    . (strlen($file['extension']) ? '.' . $file['extension'] : '');
                return $this->outputStringAsFile($converted, $filename);
            }
        }

        return $this->redirect()->toRoute('admin/thesaurus', ['action' => 'convert']);
    }

    /**
     * Convert a flat list into a flat thesaurus.
     *
     * @param string $filename
     * @param string $mediaType
     * @return string
     */
    protected function convertThesaurus($filename, $mediaType = 'text/plain')
    {
        $output = '';
        $separator = ' :: ';

        $text = file_get_contents($filename);
        // The str_replace() allows to fix Apple copy/paste.
        $lines = array_filter(array_map('trim', explode("\n", str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], $text))), function ($v) {
            return (bool) strlen($v);
        });

        $levels = [];
        foreach ($lines as $line) {
            $term = ltrim($line);
            $level = strrpos($line, "\t");
            $level = $level === false ? 0 : ++$level;
            $levels[$level] = $term;
            $row = '';
            for ($i = 0; $i < $level; ++$i) {
                $row .= isset($levels[$i]) ? $levels[$i] : '';
                $row .= $separator;
            }
            $row .= $term;
            $levels[$level] = $term;
            $output .= $row . PHP_EOL;
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
     * @return \Zend\Stdlib\ResponseInterface
     */
    protected function outputStringAsFile($text, $filename = 'output.txt', $mediaType = 'text/plain', $mode = 'attachment')
    {
        $fileSize = strlen($text);

        // Write HTTP headers
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-type: ' . $mediaType);
        $headers->addHeaderLine('Content-Disposition: ' . $mode . '; filename="' . $filename . '"');
        $headers->addHeaderLine('Content-Transfer-Encoding', 'binary');
        $headers->addHeaderLine('Content-length: ' . $fileSize);
        $headers->addHeaderLine('Cache-control: private');
        $headers->addHeaderLine('Content-Description: ' . 'File Transfer');

        // Write file content.
        $response->setContent($text);

        // Return Response to avoid default view rendering
        return $response;
    }
}
