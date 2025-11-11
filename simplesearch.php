<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Page\Collection;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Page\Types;
use Grav\Common\Plugin;
use Grav\Common\Taxonomy;
use Grav\Common\Uri;
use RocketTheme\Toolbox\Event\Event;

/**
 * SimpleSearch Plugin for Grav CMS
 *
 * Provides advanced full-text search capabilities with relevance scoring,
 * pagination, highlighting, and intelligent query parsing.
 *
 * @package    Grav\Plugin
 * @author     Team Grav
 * @license    MIT
 */
class SimplesearchPlugin extends Plugin
{
    /**
     * @var array<string> Parsed search query terms
     */
    protected $query = [];

    /**
     * @var string|null Unique query identifier for caching
     */
    protected $query_id;

    /**
     * @var Collection|null Collection of pages to search
     */
    protected $collection;

    /**
     * @var array<string, array> Search results with relevance scores
     */
    protected $scored_results = [];

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigExtensions' => ['onTwigExtensions', 0],
            'onGetPageTemplates' => ['onGetPageTemplates', 0],
        ];
    }

    /**
     * Add page template types. (for Admin plugin)
     *
     * @return void
     */
    public function onGetPageTemplates(Event $event)
    {
        /** @var Types $types */
        $types = $event->types;
        $types->scanTemplates('plugins://simplesearch/templates');
    }


    /**
     * Add current directory to twig lookup paths.
     *
     * @return void
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Add Twig extensions for search highlighting
     *
     * @return void
     */
    public function onTwigExtensions()
    {
        require_once(__DIR__ . '/twig/SimplesearchTwigExtension.php');
        $this->grav['twig']->twig->addExtension(new SimplesearchTwigExtension());
    }

    /**
     * Enable search only if url matches to the configuration.
     *
     * @return void
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
        ]);
    }


    /**
     * Build search results.
     *
     * @return void
     */
    public function onPagesInitialized()
    {
        $page = $this->grav['page'];

        $route = null;
        if (isset($page->header()->simplesearch['route'])) {
            $route = $page->header()->simplesearch['route'];

            // Support `route: '@self'` syntax
            if ($route === '@self') {
                $route = $page->route();
                $page->header()->simplesearch['route'] = $route;
            }
        }

        // If a page exists merge the configs
        if (isset($page)) {
            $this->config->set('plugins.simplesearch', $this->mergeConfig($page));
        }

        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $query = $uri->param('query') ?: $uri->query('query');
        $route = $this->config->get('plugins.simplesearch.route');

        // performance check for route
        if (!($route && $route == $uri->path())) {
            return;
        }

        // set the template is not set in the page header (the page header setting takes precedence over the plugin config setting)
        if (!isset($page->header()->template)) {
            $template_override = $this->config->get('plugins.simplesearch.template', 'simplesearch_results');
            $page->template($template_override);
        }

        // Explode query into multiple strings. Drop empty values
        // @phpstan-ignore-next-line
        $this->query = array_filter(array_filter(explode(',', $query), 'trim'), 'strlen');

        /** @var Taxonomy $taxonomy_map */
        $taxonomy_map = $this->grav['taxonomy'];
        $taxonomies = [];
        $find_taxonomy = [];

        $filters = (array)$this->config->get('plugins.simplesearch.filters');
        $operator = $this->config->get('plugins.simplesearch.filter_combinator', 'and');
        $new_approach = false;

        // if @none found, skip processing taxonomies
        $should_process = true;
        if (is_array($filters)) {
            $the_filter = reset($filters);

            if (is_array($the_filter)) {
                if (in_array(reset($the_filter), ['@none', 'none@'])) {
                    $should_process = false;
                }
            }
        }

        if (!$should_process || !$filters || $query === false || (count($filters) === 1 && !reset($filters))) {
            /** @var Pages $pages */
            $pages = $this->grav['pages'];
            $this->collection = $pages->all();
        } else {

            foreach ($filters as $key => $filter) {
                // flatten item if it's wrapped in an array
                if (is_int($key)) {
                    if (is_array($filter)) {
                        $key = key($filter);
                        $filter = $filter[$key];
                    } else {
                        $key = $filter;
                    }
                }

                // see if the filter uses the new 'items-type' syntax
                if ($key === '@self' || $key === 'self@') {
                    $new_approach = true;
                } elseif ($key === '@taxonomy' || $key === 'taxonomy@') {
                    $taxonomies = $filter === false ? false : array_merge($taxonomies, (array)$filter);
                } else {
                    $find_taxonomy[$key] = $filter;
                }
            }

            if ($new_approach) {
                $params = $page->header()->content;
                $params['query'] = $this->config->get('plugins.simplesearch.query');
                $this->collection = $page->collection($params, false);
            } else {
                $this->collection = new Collection();
                $this->collection->append($taxonomy_map->findTaxonomy($find_taxonomy, $operator)->toArray());
            }
        }

        //Drop unpublished pages, but do not drop unroutable pages right now to be able to search modular pages which are unroutable per se
        $this->collection->published();
        /** @var Collection $modularPageCollection */
        $modularPageCollection = $this->collection->copy();
        //Get published modular pages
        $modularPageCollection->modular();
        foreach ($modularPageCollection as $cpage) {
            $parent = $cpage->parent();
            if (!$parent || !$parent->published()) {
                $modularPageCollection->remove($cpage);
            }
        }
        //Drop unroutable pages
        $this->collection->routable();
        //Add modular pages again
        $this->collection->merge($modularPageCollection);

        //Allow for integration to SimpleSearch collection
        $this->grav->fireEvent('onSimpleSearchCollection', new Event(['collection' => $this->collection]));

        //Check if user has permission to view page
        if ($this->grav['config']->get('plugins.login.enabled')) {
            $this->collection = $this->checkForPermissions($this->collection);
        }
        $extras = [];

        if ($query) {
            foreach ($this->collection as $cpage) {

                $header = $cpage->header();
                if (isset($header->simplesearch['process']) && $header->simplesearch['process'] === false) {
                    $this->collection->remove($cpage);
                    continue;
                }

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
        }

        if (!empty($extras)) {
            $this->collection->append($extras);
        }

        // Sort by relevance score if we have scored results
        if (!empty($this->scored_results) && $this->config->get('plugins.simplesearch.relevance_sort', true)) {
            // Sort scored results by score (descending)
            uasort($this->scored_results, function($a, $b) {
                return $b['score'] <=> $a['score'];
            });

            // Rebuild collection with sorted pages
            $sorted_collection = new Collection();
            foreach ($this->scored_results as $path => $data) {
                if ($this->collection->offsetExists($path)) {
                    $sorted_collection[$path] = ['slug' => $data['page']->slug()];
                }
            }
            $this->collection = $sorted_collection;
        } elseif (!$new_approach) {
            // use a configured sorting order if not already done
            $this->collection = $this->collection->order(
                $this->config->get('plugins.simplesearch.order.by'),
                $this->config->get('plugins.simplesearch.order.dir')
            );
        }

        // Display simplesearch page if no page was found for the current route
        $pages = $this->grav['pages'];
        $page = $pages->dispatch($this->config->get('plugins.simplesearch.route', '/search'), true);
        if (!isset($page)) {
            // create the search page
            $page = new Page;
            $page->init(new \SplFileInfo(__DIR__ . '/pages/simplesearch.md'));

            // override the template is set in the plugin config (the plugin config setting takes precedence over the page header setting)
            $template_override = $this->config->get('plugins.simplesearch.template');
            if (isset($template_override)) {
                $page->template($template_override);
            }

            // fix RuntimeException: Cannot override frozen service "page" issue
            unset($this->grav['page']);

            $this->grav['page'] = $page;
        }
    }

    /**
     * Filter the pages, and return only the pages the user has access to.
     * Implementation based on Login Plugin authorizePage() function.
     *
     * @param Collection $collection
     * @return Collection
     */
    public function checkForPermissions($collection)
    {
        $user = $this->grav['user'];
        $returnCollection = new Collection();
        foreach ($collection as $page) {

            $header = $page->header();
            $rules = isset($header->access) ? (array)$header->access : [];

            if ($this->config->get('plugins.login.parent_acl')) {
                // If page has no ACL rules, use its parent's rules
                if (!$rules) {
                    $parent = $page->parent();
                    while (!$rules and $parent) {
                        $header = $parent->header();
                        $rules = isset($header->access) ? (array)$header->access : [];
                        $parent = $parent->parent();
                    }
                }
            }

            // Continue to the page if it has no ACL rules.
            if (!$rules) {
                $returnCollection[$page->path()] = ['slug' => $page->slug()];
            } else {
                // Continue to the page if user is authorized to access the page.
                foreach ($rules as $rule => $value) {
                    if (is_array($value)) {
                        foreach ($value as $nested_rule => $nested_value) {
                            if ($user->authorize($rule . '.' . $nested_rule) == $nested_value) {
                                $returnCollection[$page->path()] = ['slug' => $page->slug()];
                                break;
                            }
                        }
                    } else {
                        if ($user->authorize($rule) == $value) {
                            $returnCollection[$page->path()] = ['slug' => $page->slug()];
                            break;
                        }
                    }
                }
            }
        }
        return $returnCollection;
    }

    /**
     * Calculate relevance score for a page based on query matches
     *
     * @param string $query Search query
     * @param Page $page Page to score
     * @param array|false $taxonomies Taxonomy filters
     * @return float Relevance score (0 = no match, higher = better match)
     */
    private function calculateRelevanceScore(string $query, Page $page, $taxonomies): float
    {
        $score = 0.0;
        $searchable_types = $this->config->get('plugins.simplesearch.searchable_types');
        $search_content = $this->config->get('plugins.simplesearch.search_content');

        // Weight factors for different content types
        $weights = [
            'title' => 10.0,
            'taxonomy' => 5.0,
            'header' => 3.0,
            'content' => 1.0
        ];

        foreach ($searchable_types as $type => $enabled) {
            if (!$enabled) {
                continue;
            }

            $text = '';
            $weight = $weights[$type] ?? 1.0;

            if ($type === 'title') {
                $text = strip_tags($page->title());
            } elseif ($type === 'taxonomy' && $taxonomies !== false) {
                $page_taxonomies = $page->taxonomy();
                foreach ((array)$page_taxonomies as $taxonomy => $values) {
                    if (!is_array($values) || (is_array($taxonomies) && !empty($taxonomies) && !in_array($taxonomy, $taxonomies))) {
                        continue;
                    }
                    $text .= ' ' . implode(' ', $values);
                }
            } elseif ($type === 'content') {
                $text = $search_content === 'raw' ? $page->rawMarkdown() : $page->content();
                $text = strip_tags($text);
            } elseif ($type === 'header') {
                $header = (array) $page->header();
                $text = strip_tags($this->getArrayValues($header));
            }

            if ($text) {
                $score += $this->scoreText($text, $query) * $weight;
            }
        }

        return $score;
    }

    /**
     * Score text relevance for a query
     *
     * @param string $text Text to search
     * @param string $query Search query
     * @return float Score based on matches and density
     */
    private function scoreText(string $text, string $query): float
    {
        $score = 0.0;
        $text_lower = mb_strtolower($text);
        $query_lower = mb_strtolower($query);
        $text_length = mb_strlen($text);

        if ($text_length === 0) {
            return 0.0;
        }

        // Exact phrase match (highest score)
        if (mb_stripos($text, $query) !== false) {
            $score += 100.0;
        }

        // Word boundary match
        if (preg_match('/\b' . preg_quote($query_lower, '/') . '\b/ui', $text_lower)) {
            $score += 50.0;
        }

        // Count occurrences and calculate density
        $occurrences = mb_substr_count($text_lower, $query_lower);
        if ($occurrences > 0) {
            $score += $occurrences * 10.0;
            // Density bonus (prefer shorter texts with same number of matches)
            $density = $occurrences / ($text_length / 100);
            $score += $density * 5.0;
        }

        // Starts with query (early occurrence bonus)
        if (mb_strpos($text_lower, $query_lower) === 0) {
            $score += 20.0;
        }

        return $score;
    }

    /**
     * Check if page matches query (legacy method for backward compatibility)
     *
     * @param string $query Search query
     * @param Page $page Page to check
     * @param array|false $taxonomies Taxonomy filters
     * @return bool True if page does NOT match
     */
    private function notFound(string $query, Page $page, $taxonomies): bool
    {
        $score = $this->calculateRelevanceScore($query, $page, $taxonomies);

        // Store score for later sorting
        if ($score > 0) {
            $path = $page->path();
            if (!isset($this->scored_results[$path])) {
                $this->scored_results[$path] = ['page' => $page, 'score' => 0.0];
            }
            $this->scored_results[$path]['score'] += $score;
        }

        return $score === 0.0;
    }

    /**
     * @param string $haystack
     * @param string $needle
     * @return false|int
     */
    private function matchText($haystack, $needle)
    {
        if ($this->config->get('plugins.simplesearch.ignore_accented_characters')) {
            setlocale(LC_ALL, 'en_US');
            try {
                $result = mb_stripos(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $haystack), iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $needle));
            } catch (\Exception $e) {
                $result = mb_stripos($haystack, $needle);
            }
            setlocale(LC_ALL, '');
            return $result;
        }

        return mb_stripos($haystack, $needle);
    }

    /**
     * Set needed variables to display the search results.
     *
     * @return void
     */
    public function onTwigSiteVariables()
    {
        $twig = $this->grav['twig'];

        if ($this->query) {
            $twig->twig_vars['query'] = implode(', ', $this->query);
            $twig->twig_vars['search_results'] = $this->collection;

            // Pagination support
            $results_per_page = (int) $this->config->get('plugins.simplesearch.results_per_page', 10);
            $uri = $this->grav['uri'];
            $current_page = (int) ($uri->param('page') ?: $uri->query('page') ?: 1);

            if ($results_per_page > 0 && $this->collection) {
                $total_results = $this->collection->count();
                $total_pages = (int) ceil($total_results / $results_per_page);
                $current_page = max(1, min($current_page, $total_pages));
                $offset = ($current_page - 1) * $results_per_page;

                // Slice collection for current page
                $paginated_results = new Collection();
                $items = $this->collection->slice($offset, $results_per_page);
                foreach ($items as $path => $page) {
                    $paginated_results[$path] = $page;
                }

                $twig->twig_vars['search_results'] = $paginated_results;
                $twig->twig_vars['pagination'] = [
                    'current_page' => $current_page,
                    'total_pages' => $total_pages,
                    'total_results' => $total_results,
                    'results_per_page' => $results_per_page,
                    'has_prev' => $current_page > 1,
                    'has_next' => $current_page < $total_pages,
                ];
            }

            // Pass scored results for relevance display
            $twig->twig_vars['scored_results'] = $this->scored_results;
        }

        // Load CSS assets
        if ($this->config->get('plugins.simplesearch.built_in_css')) {
            if ($this->config->get('plugins.simplesearch.use_modern_assets', true)) {
                $this->grav['assets']->add('plugin://simplesearch/css/simplesearch.modern.css');
            } else {
                $this->grav['assets']->add('plugin://simplesearch/css/simplesearch.css');
            }
        }

        // Load JavaScript assets
        if ($this->config->get('plugins.simplesearch.built_in_js')) {
            if ($this->config->get('plugins.simplesearch.use_modern_assets', true)) {
                $this->grav['assets']->addJs('plugin://simplesearch/js/simplesearch.modern.js', ['group' => 'bottom']);
            } else {
                $this->grav['assets']->addJs('plugin://simplesearch/js/simplesearch.js', ['group' => 'bottom']);
            }
        }
    }

    /**
     * Highlight search terms in text
     *
     * @param string $text Text to highlight
     * @param string $query Search query
     * @param string $class CSS class for highlighting
     * @return string Text with highlighted terms
     */
    public function highlightText(string $text, string $query, string $class = 'search-highlight'): string
    {
        if (empty($query) || empty($text)) {
            return $text;
        }

        $query_lower = mb_strtolower($query);

        // Protect HTML tags from being split
        $pattern = '/(' . preg_quote($query, '/') . ')/ui';
        $replacement = '<mark class="' . $class . '">$1</mark>';

        return preg_replace($pattern, $replacement, $text);
    }

    /**
     * Extract relevant excerpt from text containing search query
     *
     * @param string $text Full text
     * @param string $query Search query
     * @param int $length Excerpt length
     * @return string Excerpt with query context
     */
    public function extractExcerpt(string $text, string $query, int $length = 200): string
    {
        $text = strip_tags($text);
        $query_lower = mb_strtolower($query);
        $text_lower = mb_strtolower($text);

        $pos = mb_strpos($text_lower, $query_lower);

        if ($pos === false) {
            // Query not found, return beginning
            return mb_substr($text, 0, $length) . (mb_strlen($text) > $length ? '...' : '');
        }

        // Center the excerpt around the query
        $start = max(0, $pos - (int)($length / 2));
        $excerpt = mb_substr($text, $start, $length);

        $prefix = $start > 0 ? '...' : '';
        $suffix = ($start + $length) < mb_strlen($text) ? '...' : '';

        return $prefix . $excerpt . $suffix;
    }

    /**
     * @param array $array
     * @param array|null $ignore_keys
     * @param int $level
     * @return string
     */
    protected function getArrayValues($array, $ignore_keys = null, $level = 0) {
        $output = '';

        if (is_null($ignore_keys)) {
            $config = $this->config();
            $ignore_keys = $config['header_keys_ignored'] ?? ['title', 'taxonomy','content', 'form', 'forms', 'media_order'];
        }
        foreach ($array as $key => $child) {

            if ($level === 0 && in_array($key, $ignore_keys, true)) {
                continue;
            }

            if (is_array($child)) {
                $output .= " " . $this->getArrayValues($child, $ignore_keys, $level + 1);
            } else {
                $output .= " " . $child;
            }

        }
        return trim($output);
    }
}
