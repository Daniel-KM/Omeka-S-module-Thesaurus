<?php
namespace Thesaurus\View\Helper;

use Omeka\Api\Representation\ItemRepresentation;
use Zend\View\Helper\AbstractHelper;

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
     * @var \Omeka\View\Helper\Hyperlink
     */
    protected $hyperlink;

    /**
     * Display a link to a term via a prepared query for performance.
     *
     * @param array $options "link": term, resource, none or both; "term", the
     * property to use for resource links; "browseString", the string to use to
     * browse resource when link is both.
     * @return self
     */
    public function __invoke(array $options = [])
    {
        $view = $this->getView();

        $this->options = $options + [
            'link' => 'both',
            'term' => 'dcterms:subject',
            'browseString' => $view->translate('browse'), // @translate
        ];

        $query = [
            'property' => [
                [
                    'property' => $this->options['term'],
                    'type' => 'res',
                    'text' => '__link_term_id__',
                ],
            ],
        ];
        $this->templateUrl = $view->url('site/resource', ['site-slug' => $this->currentSite()->slug(), 'controller' => 'item'], ['query' => $query], false);

        $this->hyperlink = $view->plugin('hyperlink');

        return $this;
    }

    /**
     * Display data according to options.
     *
     * @param ItemRepresentation $data
     * @return string
     */
    public function render(ItemRepresentation $data)
    {
        switch ($this->options['link']) {
            case 'term':
                return $data->link($data->displayTitle());
            case 'resource':
                return $this->hyperlink->raw($data->displayTitle(), str_replace('__link_term_id__', $data->id(), $this->templateUrl));
            case 'none':
                return $data->displayTitle();
            case 'both':
            default:
                return $data->link($data->displayTitle())
                    . ' ('
                    . $this->hyperlink->raw($this->options['browseString'], str_replace('__link_term_id__', $data->id(), $this->templateUrl))
                    . ')';
        }
    }

    /**
     * @return \Omeka\Api\Representation\SiteRepresentation
     */
    protected function currentSite()
    {
        $view = $this->getView();
        return isset($view->site)
            ? $view->site
            : $view->getHelperPluginManager()->get('Zend\View\Helper\ViewModel')->getRoot()->getVariable('site');
    }
}
