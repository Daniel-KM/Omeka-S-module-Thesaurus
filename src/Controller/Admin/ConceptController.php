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
 * A concept requires a scheme, so it is added from its thesaurus or from its
 * broader concept.
 */
class ConceptController extends AbstractActionController
{
    /**
     * Add a concept to a thesaurus, as top concept or under a broader one.
     */
    public function addAction()
    {
        $schemeId = (int) $this->params()->fromQuery('scheme_id');
        $broaderId = (int) $this->params()->fromQuery('broader_id');

        /** @var \Omeka\Api\Representation\ItemRepresentation $scheme */
        $scheme = $this->api()->read('items', ['id' => $schemeId])->getContent();
        /** @var \Thesaurus\Api\Representation\ConceptRepresentation|null $broader */
        $broader = $broaderId
            ? $this->api()->read('concepts', ['id' => $broaderId])->getContent()
            : null;
        if ($broader && $broader->scheme()->id() !== $scheme->id()) {
            $broader = null;
        }

        $form = $this->getForm(ResourceForm::class);
        $form->setAttribute('action', $this->url()->fromRoute(null, [], ['query' => array_filter([
            'scheme_id' => $scheme->id(),
            'broader_id' => $broader ? $broader->id() : null,
        ])], true));
        $form->setAttribute('id', 'add-concept');

        $settings = $this->settings();
        $form->get('o:resource_template[o:id]')->setValue((int) $settings->get('thesaurus_skos_concept_template_id'));
        $form->get('o:resource_class[o:id]')->setValue((int) $settings->get('thesaurus_skos_concept_class_id'));

        $postedValues = $this->prefilledValues($scheme, $broader);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $postedValuesJson = $data['values_json'] ?? null;
            $data = $this->mergeValuesJson($data);
            $form->setData($data);
            if ($form->isValid()) {
                // The concept is appended to its siblings, so the tree does not
                // need to be reindexed.
                $data['o-module-thesaurus:position'] ??= $this->nextPosition(
                    $scheme->id(),
                    (int) ($data['skos:broader'][0]['value_resource_id'] ?? 0)
                );
                $response = $this->api($form)->create('concepts', $data);
                if ($response) {
                    $message = new \Common\Stdlib\PsrMessage(
                        'Concept successfully created. {link}Add another concept?{link_end}', // @translate
                        [
                            'link' => sprintf('<a href="%s">', htmlspecialchars($this->url()->fromRoute(null, [], ['query' => $this->params()->fromQuery()], true))),
                            'link_end' => '</a>',
                        ]
                    );
                    $message->setEscapeHtml(false);
                    $this->messenger()->addSuccess($message);
                    return $this->redirect()->toUrl($response->getContent()->url());
                }
            } else {
                $this->messenger()->addFormErrors($form);
            }
            $decoded = $postedValuesJson ? json_decode($postedValuesJson, true) : null;
            if (is_array($decoded)) {
                $postedValues = array_map(fn ($values) => ['values' => $values], array_filter($decoded, 'is_array'));
            }
        }

        return new ViewModel([
            'form' => $form,
            'scheme' => $scheme,
            'broader' => $broader,
            'postedValues' => $postedValues,
        ]);
    }

    /**
     * Prepare the values of a new concept: its scheme and its broader concept.
     */
    protected function prefilledValues($scheme, $broader): array
    {
        $easyMeta = $this->easyMeta();
        $link = fn ($term, $dataType, $resource) => [
            $term => ['values' => [[
                'type' => $dataType,
                'property_id' => $easyMeta->propertyId($term),
                'is_public' => true,
                'value_resource_id' => $resource->id(),
                'value_resource_name' => $resource->resourceName(),
                'display_title' => $resource->displayTitle(),
                'url' => $resource->adminUrl(),
            ]]],
        ];

        return $link('skos:inScheme', 'resource:item', $scheme)
            + ($broader
                ? $link('skos:broader', 'resource:concept', $broader)
                : $link('skos:topConceptOf', 'resource:item', $scheme));
    }

    /**
     * Get the position after the last sibling of a concept.
     */
    protected function nextPosition(int $schemeId, int $broaderId): int
    {
        $positions = $this->api()->search('concepts', [
            'scheme_id' => $schemeId,
            // The id "0" means the top concepts.
            'broader_id' => $broaderId,
        ], ['returnScalar' => 'position'])->getContent();
        return $positions ? (int) max($positions) + 1 : 1;
    }

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

    /**
     * List the concepts to select in the sidebar of the resource form.
     */
    public function sidebarSelectAction()
    {
        $this->browse()->setDefaults('concepts');
        $query = $this->params()->fromQuery();
        $response = $this->api()->search('concepts', $query);
        $this->paginator($response->getTotalResults());

        $view = new ViewModel([
            'concepts' => $response->getContent(),
            'search' => $query['search'] ?? '',
            'schemeId' => $query['scheme_id'] ?? null,
            'id' => $query['id'] ?? null,
            'showDetails' => true,
        ]);
        return $view->setTerminal(true);
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
