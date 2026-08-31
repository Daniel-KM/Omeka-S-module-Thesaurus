<?php declare(strict_types=1);

namespace Thesaurus\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Omeka\Form\ConfirmForm;
use Omeka\Form\ResourceForm;

/**
 * Admin controller for concepts: a concept is a resource type, but it has
 * neither file nor collection, and it is mainly managed inside its thesaurus
 * (import and structure editor).
 *
 * A concept is created by the thesaurus (import or structure editor), because
 * it requires a scheme, so there is no "add" action here: only the values and
 * the visibility can be edited.
 */
class ConceptController extends AbstractActionController
{
    public function showAction()
    {
        $concept = $this->api()->read('concepts', $this->params('id'))->getContent();

        return new ViewModel([
            'resource' => $concept,
            'concept' => $concept,
        ]);
    }

    public function showDetailsAction()
    {
        $concept = $this->api()->read('concepts', $this->params('id'))->getContent();
        $view = new ViewModel([
            'resource' => $concept,
            'linkTitle' => (bool) $this->params()->fromQuery('link-title', true),
            'values' => json_encode($concept->valueRepresentation()),
        ]);
        return $view->setTerminal(true);
    }

    public function editAction()
    {
        /** @var \Thesaurus\Api\Representation\ConceptRepresentation $concept */
        $concept = $this->api()->read('concepts', $this->params('id'))->getContent();

        $form = $this->getForm(ResourceForm::class, ['resource' => $concept]);
        $form->setAttribute('id', 'edit-concept');

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            // The values are posted as a single json field to bypass the limit
            // of php max_input_vars, like for the other resources.
            $data = $this->mergeValuesJson($data);
            $form->setData($data);
            if ($form->isValid()) {
                $response = $this->api($form)->update('concepts', $this->params('id'), $data);
                if ($response) {
                    $this->messenger()->addSuccess('Concept successfully updated'); // @translate
                    return $this->redirect()->toUrl($response->getContent()->url());
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        return new ViewModel([
            'form' => $form,
            'resource' => $concept,
            'concept' => $concept,
        ]);
    }

    public function deleteConfirmAction()
    {
        $linkTitle = (bool) $this->params()->fromQuery('link-title', true);
        $concept = $this->api()->read('concepts', $this->params('id'))->getContent();

        $view = new ViewModel([
            'resource' => $concept,
            'resourceLabel' => 'concept', // @translate
            'partialPath' => 'thesaurus/admin/concept/show-details',
            'linkTitle' => $linkTitle,
            'values' => json_encode($concept->valueRepresentation()),
        ]);
        return $view
            ->setTerminal(true)
            ->setTemplate('common/delete-confirm-details');
    }

    public function deleteAction()
    {
        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $response = $this->api($form)->delete('concepts', $this->params('id'));
                if ($response) {
                    $this->messenger()->addSuccess('Concept successfully deleted'); // @translate
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }
        return $this->redirect()->toRoute('admin/thesaurus/default');
    }
}
