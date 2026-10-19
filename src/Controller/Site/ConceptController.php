<?php declare(strict_types=1);

namespace Thesaurus\Controller\Site;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class ConceptController extends AbstractActionController
{
    public function showAction()
    {
        $concept = $this->api()->read('concepts', $this->params('id'))->getContent();

        // The variables of an item are set, so the template of the items of the
        // theme can be used.
        return new ViewModel([
            'site' => $this->currentSite(),
            'concept' => $concept,
            'item' => $concept,
            'resource' => $concept,
        ]);
    }
}
