<?php declare(strict_types=1);

namespace Thesaurus\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;

class LinkTerm extends AbstractHelper
{
    /**
     * @var array
     */
    protected $options;

    /**
     * @var string
     */
    protected $templateUrl;

    /**
     * @var string
     */
    protected $templateResourceUrl;

    /**
     * @var \Omeka\View\Helper\Hyperlink
     */
    protected $hyperlink;

    /**
     * @var \Laminas\View\Helper\Url
     */
    protected $url;

    /**
     * @var \Omeka\View\Helper\Api
     */
    protected $api;

    /**
     * Display a link to a term via a prepared query for performance.
     *
     * @param array $options "link": term, resource, none or both; "term", the
     * property to use for resource links; "browseString", the string to use to
     * browse resource when link is both.
     */
    public function __invoke(?array $options = []): self
    {
        $view = $this->getView();
        $plugins = $view->getHelperPluginManager();
        $this->hyperlink = $plugins->get('hyperlink');
        $this->url = $plugins->get('url');
        $this->api = $plugins->get('api');
        $translate = $plugins->get('translate');
        $urlHelper = $this->url;
        $currentSiteSlug = $this->currentSite()->slug();

        $defaultOptions = [
            'link' => 'both',
            'term' => 'dcterms:subject',
            'browseString' => $translate('browse'), // @translate
        ];
        $this->options = $options
            ? $options + $defaultOptions
            : $defaultOptions;

        $query = [
            'property' => [
                [
                    'property' => $this->options['term'],
                    'type' => 'res',
                    'text' => '__link_term_id__',
                ],
            ],
        ];
        $this->templateUrl = $urlHelper('site/resource', ['site-slug' => $currentSiteSlug, 'controller' => 'item'], ['query' => $query], false);

        $this->templateResourceUrl = $urlHelper('site/resource-id', ['site-slug' => $currentSiteSlug, 'controller' => 'item', 'id' => 0]);
        $this->templateResourceUrl = mb_substr($this->templateResourceUrl, 0, mb_strlen($this->templateResourceUrl) - 1);

        return $this;
    }

    /**
     * Display data according to options.
     *
     * @param ItemRepresentation|array $data
     */
    public function render($data): string
    {
        return is_object($data)
            ? $this->renderItem($data)
            : $this->renderData($data);
    }

    /**
     * Helper to get the item representation from item data.
     *
     * It may be used to translate a term, waiting for a full implementation of
     * the internationalisation of  the title.
     *
     * @see \Thesaurus\Mvc\Controller\Plugin\Thesaurus::itemFromData()
     */
    public function itemFromData(?array $itemData = null): ?ItemRepresentation
    {
        return $itemData
            ? $this->api->searchOne('items', ['id' => $itemData['id']], ['initialize' => false])->getContent()
            : null;
    }

    /**
     * Display term data according to options.
     */
    protected function renderData(array $termData): string
    {
        switch ($this->options['link']) {
            case 'term':
                return $this->hyperlink->raw($termData['title'], $this->templateResourceUrl . $termData['id']);
            case 'resource':
                return $this->hyperlink->raw($termData['title'], str_replace('__link_term_id__', $termData['id'], $this->templateUrl));
            case 'none':
                return $termData['title'];
            case 'both':
            default:
                return $this->hyperlink->raw($termData['title'], $this->templateResourceUrl . $termData['id'])
                    . ' ('
                    . $this->hyperlink->raw($this->options['browseString'], str_replace('__link_term_id__', $termData['id'], $this->templateUrl))
                    . ')';
        }
    }

    /**
     * Display term item according to options.
     *
     * @deprecated
     */
    protected function renderItem(ItemRepresentation $item): string
    {
        switch ($this->options['link']) {
            case 'term':
                return $item->link($item->displayTitle());
            case 'resource':
                return $this->hyperlink->raw($item->displayTitle(), str_replace('__link_term_id__', $item->id(), $this->templateUrl));
            case 'none':
                return $item->displayTitle();
            case 'both':
            default:
                return $item->link($item->displayTitle())
                    . ' ('
                    . $this->hyperlink->raw($this->options['browseString'], str_replace('__link_term_id__', $item->id(), $this->templateUrl))
                    . ')';
        }
    }

    /**
     * Get the current site from the view.
     */
    protected function currentSite(): ?\Omeka\Api\Representation\SiteRepresentation
    {
        return $this->view->site ?? $this->view->site = $this->view
            ->getHelperPluginManager()
            ->get('Laminas\View\Helper\ViewModel')
            ->getRoot()
            ->getVariable('site');
    }
}
