<?php
namespace Grav\Plugin;

use Grav\Common\Page\Collection;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Common\Page\Page;
use Grav\Common\Page\Types;
use Grav\Common\Taxonomy;
use Grav\Common\Utils;
use Grav\Common\Data\Data;
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
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onGetPageTemplates' => ['onGetPageTemplates', 0],
        ];
    }

    /**
     * Add page template types. (for Admin plugin)
     */
    public function onGetPageTemplates(Event $event)
    {
        /** @var Types $types */
        $types = $event->types;
        $types->scanTemplates('plugins://simplesearch/templates');
    }


    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Enable search only if url matches to the configuration.
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 0]
        ]);
    }


    /**
     * Build search results.
     */
    public function onPagesInitialized()
    {
        $page = $this->grav['page'];

        // If a page exists merge the configs
        if ($page) {
            $this->config->set('plugins.simplesearch', $this->mergeConfig($page));
        }

        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $query = $uri->param('query') ?: $uri->query('query');
        $route = $this->config->get('plugins.simplesearch.route');

        // performance check
        if ($route && $query && $route == $uri->path()) {
            $this->enable([
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
            ]);
        } else {
            return;
        }

        $this->query = explode(',', $query);

        /** @var Taxonomy $taxonomy_map */
        $taxonomy_map = $this->grav['taxonomy'];
        $taxonomies = [];

        $filters = (array) $this->config->get('plugins.simplesearch.filters');
        $operator = $this->config->get('plugins.simplesearch.filter_combinator', 'and');

        $new_approach = false;
        if ( ! $filters) {
            /** @var \Grav\Common\Page\Pages $pages */
            $pages = $this->grav['pages'];

            $this->collection = $pages->all();
        } else {
            // see if the filter uses the new 'items-type' syntax
            foreach ($filters as $filter) {
                $filter_saved = $filter;
                if (is_array($filter)) {
                    $filter = key($filter);
                }
                if (Utils::startsWith($filter, '@')) {
                    if ($filter == '@self') {
                        $new_approach = true;
                    }
                    if ($filter == '@taxonomy' && is_array($filter_saved)) {
                        $taxonomies = $filter_saved[$filter];
                    }
                }
            }

            if ($new_approach) {
                $params = $page->header()->content;
                $params['query'] = $this->config->get('plugins.simplesearch.query');
                $this->collection = $page->collection($params, false);
            } else {
                $this->collection = new Collection();
                $this->collection->append($taxonomy_map->findTaxonomy($filters, $operator)->toArray());
            }
        }


        $extras = [];

        /** @var Page $cpage */
        foreach ($this->collection as $cpage) {
            foreach ($this->query as $query) {
                $query = trim($query);

                if ($this->notFound($query, $cpage, $taxonomies)) {
                    $this->collection->remove($cpage);
                    continue;
                }

                if ($cpage->modular()) {
                    $this->collection->remove($cpage);
                    $parent = $cpage->parent();
                    $extras[$parent->path()] = ['slug' => $parent->slug()];
                }

            }
        }

        if (!empty($extras)) {
            $this->collection->append($extras);
        }

        // use a configured sorting order if not already done
        if (!$new_approach) {
            $this->collection = $this->collection->order(
                $this->config->get('plugins.simplesearch.order.by'),
                $this->config->get('plugins.simplesearch.order.dir')
            );
        }

        // if page doesn't have settings set, create a page
        if (!isset($page->header()->simplesearch)) {
            // create the search page
            $page = new Page;
            $page->init(new \SplFileInfo(__DIR__ . '/pages/simplesearch.md'));

            // override the template is set in the config
            $template_override = $this->config->get('plugins.simplesearch.template');
            if ($template_override) {
                $page->template($template_override);
            }

            // fix RuntimeException: Cannot override frozen service "page" issue
            unset($this->grav['page']);

            $this->grav['page'] = $page;
        }
    }


    /**
     * Set needed variables to display the search results.
     */
    public function onTwigSiteVariables()
    {
        $twig = $this->grav['twig'];
        $twig->twig_vars['query'] = implode(', ', $this->query);

        $twig->twig_vars['search_results'] = $this->collection;

        if ($this->config->get('plugins.simplesearch.built_in_css')) {
            $this->grav['assets']->add('plugin://simplesearch/css/simplesearch.css');
        }
    }

    private function notFound($query, $page, $taxonomies)
    {
        $searchable_types = ['title', 'content', 'taxonomy'];
        $results = true;
        foreach ($searchable_types as $type) {
            if ($type === 'title') {
                $result = mb_stripos(strip_tags($page->title()), $query) === false;
            } elseif ($type === 'taxonomy') {
                if ($taxonomies === false) {
                    continue;
                }
                $page_taxonomies = $page->taxonomy();
                $taxonomy_match = false;
                foreach ((array) $page_taxonomies as $taxonomy => $values) {
                    // if taxonomies filter set, make sure taxonomy filter is valid
                    if (is_array($taxonomies) && !empty($taxonomies) && !in_array($taxonomy, $taxonomies)) {
                        continue;
                    }

                    $taxonomy_values = implode('|',$values);
                    if (mb_stripos($taxonomy_values, $query) !== false) {
                        $taxonomy_match = true;
                        break;
                    }
                }
                $result = !$taxonomy_match;
            } else {
                $result = mb_stripos(strip_tags($page->content()), $query) === false;
            }
            $results = $results && $result;
            if ($results === false ) {
                break;
            }
        }
        return $results;
    }

}
