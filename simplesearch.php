<?php
namespace Grav\Plugin;

use Grav\Common\Page\Collection;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Common\Page\Page;
use Grav\Common\Taxonomy;

class SimplesearchPlugin extends Plugin
{
    /**
     * @var array
     */
    protected $query;

    /**
     * @var string
     */
    protected $query_id;

    /**
     * @var Collection
     */
    protected $collection;

    /**
     * @return array
     */
    public static function getSubscribedEvents() {
        return [
            'onAfterInitPlugins' => ['onAfterInitPlugins', 0]
        ];
    }
    /**
     * Enable search only if url matches to the configuration.
     */
    public function onAfterInitPlugins()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $query = $uri->param('query');
        $route = $this->config->get('plugins.simplesearch.route');

        if ($route && $route == $uri->path() && $query) {
            $this->query = explode(',', $query);

            // disable debugger if JSON format
            if ($uri->extension() == 'json') {
                $this->config->set('system.debugger.enabled', false);
            }

            $this->enable([
                'onAfterGetPages' => ['onAfterGetPages', 0],
                'onAfterGetPage' => ['onAfterGetPage', 0],
                'onAfterTwigTemplatesPaths' => ['onAfterTwigTemplatesPaths', 0],
                'onAfterTwigSiteVars' => ['onAfterTwigSiteVars', 0]
            ]);
        }
    }

    /**
     * Build search results.
     */
    public function onAfterGetPages()
    {
        /** @var Taxonomy $taxonomy_map */
        $taxonomy_map = $this->grav['taxonomy'];

        $filters = (array) $this->config->get('plugins.simplesearch.filters');

        $this->collection = new Collection();
        foreach ($filters as $taxonomy => $items) {
            if (isset($items)) {
                $this->collection->append($taxonomy_map->findTaxonomy([$taxonomy => $items])->toArray());
            }
        }

        /** @var Page $page */
        foreach ($this->collection as $page) {
            foreach ($this->query as $query) {
                $query = trim($query);

                if (stripos($page->content(), $query) === false && stripos($page->title(), $query) === false) {
                    $this->collection->remove($page);
                }
            }
        }
    }

    /**
     * Create search result page.
     */
    public function onAfterGetPage()
    {
        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . '/pages/simplesearch.md'));

        // override the template is set in the config
        $template_override = $this->config->get('plugins.simplesearch.template');
        if ($template_override) {
            $page->template($template_override);
        }

        $this->grav['page'] = $page;
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onAfterTwigTemplatesPaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Set needed variables to display the search results.
     */
    public function onAfterTwigSiteVars()
    {
        $twig = $this->grav['twig'];
        $twig->twig_vars['query'] = implode(', ', $this->query);

        $twig->twig_vars['search_results'] = $this->collection;

        if ($this->config->get('plugins.simplesearch.built_in_css')) {
            $this->grav['assets']->add('@plugin/simplesearch/css:simplesearch.css');
        }
    }
}
