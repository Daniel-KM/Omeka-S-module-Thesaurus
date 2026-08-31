<?php declare(strict_types=1);

namespace Thesaurus\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

/**
 * Minimal admin controller for concepts: a concept is a resource type, but it
 * is mainly managed inside its thesaurus (import and structure editor).
 *
 * The controller uses item show templates, since a concept has the same values
 * as an item.
 */
class ConceptController extends AbstractActionController
{
    public function showAction()
    {
        $concept = $this->api()->read('concepts', $this->params('id'))->getContent();

        $view = new ViewModel([
            'resource' => $concept,
            'concept' => $concept,
        ]);
        return $view
            ->setTemplate('omeka/admin/item/show');
    }

    public function showDetailsAction()
    {
        $concept = $this->api()->read('concepts', $this->params('id'))->getContent();
        $view = new ViewModel([
            'resource' => $concept,
        ]);
        return $view
            ->setTerminal(true)
            ->setTemplate('omeka/admin/item/show-details');
    }
}
