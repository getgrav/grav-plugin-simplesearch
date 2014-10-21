<?php
namespace Grav\Plugin;

use Grav\Common\Page\Collection;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Common\Page\Page;
use Grav\Common\Page\Types;
use Grav\Common\Taxonomy;
use RocketTheme\Toolbox\Event\Event;

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
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onGetPageTemplates' => ['onGetPageTemplates', 0],
        ];
    }
    /**
     * Enable search only if url matches to the configuration.
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            $this->active = false;
            return;
        }

        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $query = $uri->param('query') ?: $uri->query('query');
        $route = $this->config->get('plugins.simplesearch.route');

        if ($route && $route == $uri->path() && $query) {
            $this->query = explode(',', $query);
            $this->active = true;

            $this->enable([
                'onPagesInitialized' => ['onPagesInitialized', 0],
                'onPageInitialized' => ['onPageInitialized', 0],
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
            ]);
        }
    }

    /**
     * Build search results.
     */
    public function onPagesInitialized()
    {
        if (!$this->active) return;

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
    public function onPageInitialized()
    {
        if (!$this->active) return;

        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . '/pages/simplesearch.md'));

        // override the template is set in the config
        $template_override = $this->config->get('plugins.simplesearch.template');
        if ($template_override) {
            $page->template($template_override);
        }

        // allows us to redefine the page service without triggering RuntimeException: Cannot override frozen service
        // "page" issue
        unset($this->grav['page']);

        $this->grav['page'] = $page;
    }

    /**
     * Add page template types.
     */
    public function onGetPageTemplates(Event $event)
    {
        if (!$this->active) return;

        /** @var Types $types */
        $types = $event->types;
        $types->scanTemplates('plugins://simplesearch/templates');
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        if (!$this->active) return;

        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Set needed variables to display the search results.
     */
    public function onTwigSiteVariables()
    {
        if (!$this->active) return;

        $twig = $this->grav['twig'];
        $twig->twig_vars['query'] = implode(', ', $this->query);

        $twig->twig_vars['search_results'] = $this->collection;

        if ($this->config->get('plugins.simplesearch.built_in_css')) {
            $this->grav['assets']->add('plugin://simplesearch/css/simplesearch.css');
        }
    }
}
