<?php

namespace Grav\Plugin;

use Grav\Common\Page\Collection;
use Grav\Common\Page\Header;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Page\Types;
use Grav\Common\Plugin;
use Grav\Common\Taxonomy;
use Grav\Common\Uri;
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
     * @var ?array
     */
    protected $pagination_details = null;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onGetPageTemplates' => ['onGetPageTemplates', 0],
            'onTask.simplesearch.searchSuggestions' => ['onAjaxSearchSuggestions', 0],
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

        // use a configured sorting order if not already done
        if (!$new_approach) {
            $this->collection = $this->collection->order(
                $this->config->get('plugins.simplesearch.order.by'),
                $this->config->get('plugins.simplesearch.order.dir')
            );
        }

        // Determine if this is an AJAX request for full results
        $is_ajax_request = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
        $is_ajax_results_request = $is_ajax_request && 
                                   $this->config->get('plugins.simplesearch.enable_ajax_search') && 
                                   ($uri->param('ajax_results') || $uri->query('ajax_results'));

        if ($this->query) { // Only proceed if there's a query
            if ($is_ajax_results_request) {
                // AJAX full results processing
                $output_results = [];
                // For AJAX, we send all results and let client handle pagination based on this data,
                // or the client can send a page param which we'd use to slice $this->collection.
                // Current JS sends all results and paginates client-side, so no server-side slice for AJAX here.
                // However, the pagination data should still reflect the full set.
                $total_results_ajax = $this->collection->count();
                $per_page_ajax = (int)$this->config->get('plugins.simplesearch.per_page', 10);
                $current_page_ajax = (int)($uri->param('page') ?: $uri->query('page') ?: 1); // Client might send this
                $total_pages_ajax = $total_results_ajax > 0 ? ceil($total_results_ajax / $per_page_ajax) : 0;

                foreach ($this->collection as $cpage) { // Iterate over potentially full collection for AJAX
                    $page_content_raw = $this->config->get('plugins.simplesearch.search_content', 'rendered') === 'raw'
                                       ? $cpage->rawMarkdown()
                                       : $cpage->content();
                    $snippet_plain = mb_substr(strip_tags($page_content_raw), 0, 200) . '...';

                    $output_results[] = [
                        'title' => $this->highlightQueryTerms($cpage->title(), $this->query),
                        'url' => $cpage->url(),
                        'content_snippet' => $this->highlightQueryTerms($snippet_plain, $this->query),
                    ];
                }
                // If server-side pagination for AJAX is desired in future:
                // $offset = ($current_page_ajax - 1) * $per_page_ajax;
                // $output_results = array_slice($output_results, $offset, $per_page_ajax);

                $pagination_data_ajax = [
                    'total_results' => $total_results_ajax,
                    'per_page' => $per_page_ajax,
                    'current_page' => $current_page_ajax,
                    'total_pages' => $total_pages_ajax,
                ];

                header('Content-Type: application/json');
                echo json_encode([
                    'query' => implode(', ', $this->query),
                    'results' => $output_results, // This might be the full set or sliced if server-side AJAX pagination
                    'pagination' => $pagination_data_ajax,
                ]);
                exit;
            } else {
                // Non-AJAX HTML results: Paginate the collection server-side
                $current_page = (int)($uri->param('page') ?: $uri->query('page') ?: 1);
                $per_page = (int)$this->config->get('plugins.simplesearch.per_page', 10);
                $total_results = $this->collection->count();

                if ($total_results > 0) {
                    $total_pages = ceil($total_results / $per_page);
                    if ($current_page < 1) $current_page = 1;
                    if ($current_page > $total_pages) $current_page = $total_pages;

                    $this->collection = $this->collection->slice(($current_page - 1) * $per_page, $per_page);
                    
                    $this->pagination_details = [
                        'total_results' => $total_results,
                        'current_page' => $current_page,
                        'per_page' => $per_page,
                        'total_pages' => $total_pages,
                        // base_url will be added in onTwigSiteVariables using page context
                    ];
                } else {
                     $this->pagination_details = [ // Still set empty pagination data
                        'total_results' => 0,
                        'current_page' => 1,
                        'per_page' => $per_page,
                        'total_pages' => 0,
                    ];
                }
            }
        } else { // No query
             $this->pagination_details = [
                'total_results' => 0,
                'current_page' => 1,
                'per_page' => (int)$this->config->get('plugins.simplesearch.per_page', 10),
                'total_pages' => 0,
            ];
        }


        // Display simplesearch page if no page was found for the current route (for non-AJAX requests)
        // This part should only run for non-AJAX requests. AJAX requests would have exited.
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
     * @param string $query
     * @param Page $page
     * @param array|false $taxonomies
     * @return bool
     */
    private function notFound($query, $page, $taxonomies)
    {
        $searchable_types = $search_content = $this->config->get('plugins.simplesearch.searchable_types');
        $results = true;
        $search_content = $this->config->get('plugins.simplesearch.search_content');

        $result = null;
        foreach ($searchable_types as $type => $enabled) {
            if ($type === 'title' && $enabled) {
                $result = $this->matchText(strip_tags($page->title()), $query) === false;
            } elseif ($type === 'taxonomy' && $enabled) {
                if ($taxonomies === false) {
                    continue;
                }
                $page_taxonomies = $page->taxonomy();
                $taxonomy_match = false;
                foreach ((array)$page_taxonomies as $taxonomy => $values) {
                    // if taxonomies filter set, make sure taxonomy filter is valid
                    if (!is_array($values) || (is_array($taxonomies) && !empty($taxonomies) && !in_array($taxonomy, $taxonomies))) {
                        continue;
                    }

                    $taxonomy_values = implode('|', $values);
                    if ($this->matchText($taxonomy_values, $query) !== false) {
                        $taxonomy_match = true;
                        break;
                    }
                }
                $result = !$taxonomy_match;
            } elseif ($type === 'content' && $enabled) {
                if ($search_content === 'raw') {
                    $content = $page->rawMarkdown();
                } else {
                    $content = $page->content();
                }
                $result = $this->matchText(strip_tags($content), $query) === false;
            } elseif ($type === 'header' && $enabled) {
                // Flex pages return a Header object whose data is a protected
                // property, so a plain (array) cast yields one mangled "\0*\0items"
                // key and header_keys_ignored silently stops matching.
                $header = $page->header();
                $header = $header instanceof Header ? $header->toArray() : (array) $header;
                $content = $this->getArrayValues($header);
                $result = $this->matchText(strip_tags($content), $query) === false;
            }
            $results = (bool)$result;
            if ($results === false) {
                break;
            }
        }
        return $results;
    }

    /**
     * @param string $haystack
     * @param string $needle
     * @return false|int
     */
    private function matchText($haystack, $needle)
    {
        if ($this->config->get('plugins.simplesearch.ignore_accented_characters')) {
            return mb_stripos(static::deaccent($haystack), static::deaccent($needle));
        }

        return mb_stripos($haystack, $needle);
    }

    /**
     * Fold accented characters to their ASCII equivalents for accent-insensitive search.
     *
     * Uses the intl Transliterator, which is locale-independent (no setlocale global-state
     * mutation) and covers non-Latin-1 scripts that the old iconv//TRANSLIT approach silently
     * dropped. The Any-Latin; Latin-ASCII chain matches Grav core's symfony/string convention
     * and correctly folds both the comma-below and cedilla forms of characters like ș/ț.
     * Falls back to the raw text (plain mb_stripos matching) when ext-intl is unavailable.
     *
     * @param string $text
     * @return string
     */
    private static function deaccent($text)
    {
        static $tl = false;
        if ($tl === false) {
            $tl = class_exists(\Transliterator::class)
                ? \Transliterator::create('Any-Latin; Latin-ASCII')
                : null;
        }

        return $tl ? $tl->transliterate($text) : $text;
    }

    /**
     * Set needed variables to display the search results.
     *
     * @return void
     */
    public function onTwigSiteVariables()
    {
        $twig = $this->grav['twig'];

        if ($this->query && isset($this->collection)) {
            $twig->twig_vars['query'] = implode(', ', $this->query);
            $twig->twig_vars['search_results'] = $this->collection;

            // Prepare highlighted versions for non-AJAX display
            // Check if this is NOT an AJAX request for full results, as that's handled separately
            $uri = $this->grav['uri'];
            $is_ajax_request = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
            $is_ajax_results_request = $is_ajax_request && 
                                       $this->config->get('plugins.simplesearch.enable_ajax_search') && 
                                       ($uri->param('ajax_results') || $uri->query('ajax_results'));

            if (!$is_ajax_results_request && $this->collection->count() > 0) {
                $highlighted_titles = [];
                $highlighted_snippets = [];
                // $this->collection is now paginated for non-AJAX requests
                foreach ($this->collection as $page_item) { // Renamed to avoid conflict with outer $page
                    $page_content_raw = $this->config->get('plugins.simplesearch.search_content', 'rendered') === 'raw'
                                       ? $page_item->rawMarkdown()
                                       : $page_item->content();
                    $snippet_plain = mb_substr(strip_tags($page_content_raw), 0, 200) . '...';

                    $highlighted_titles[$page_item->path()] = $this->highlightQueryTerms($page_item->title(), $this->query);
                    $highlighted_snippets[$page_item->path()] = $this->highlightQueryTerms($snippet_plain, $this->query);
                }
                $twig->twig_vars['highlighted_titles'] = $highlighted_titles;
                $twig->twig_vars['highlighted_snippets'] = $highlighted_snippets;
            }
            
            // Pass pagination details to Twig for non-AJAX requests
            if (!$is_ajax_results_request && $this->pagination_details) {
                $current_search_page = $this->grav['page']; // This should be the search results page itself
                $this->pagination_details['base_url'] = $current_search_page->url();
                // Ensure query parameters are part of the base_url for pagination links if using standard URL query params
                // However, Grav uses segment params, so `uri.params` will be appended in Twig.
                // For segment based like /query:foo, the base_url should be /search-results-page/query:foo
                // And then /page:N is added.
                // If $current_search_page->url() is just /search-results-page, then we need to add query params.
                // Let's build the base_url for pagination to include the query params correctly.
                
                $base_pagination_url = $current_search_page->route();
                $query_params_for_link = [];
                if ($this->query) {
                    // Assuming $this->query is an array of terms and we want to pass it as a single 'query' param
                    $query_string = implode(' ', $this->query); // Or how it was originally passed
                     // Check if $uri->params() already contains the query.
                    $current_uri_params = $uri->params(null, true); // Get as array
                    if (isset($current_uri_params['query'])) {
                         $base_pagination_url = rtrim($current_search_page->url(), '/');
                         // remove /page:X if it exists from current url for base
                         $base_pagination_url = preg_replace('/\/page' . preg_quote($this->grav['config']->get('system.param_sep')) . '\d+$/', '', $base_pagination_url);

                    } else {
                        // This case might not happen if route is /search/query:myterm
                        // If route is just /search and query is from ?query=myterm, then this is needed.
                        // For now, assuming Grav's segment based routing is primary.
                        // $base_pagination_url .= $this->grav['config']->get('system.param_sep') . 'query' . $this->grav['config']->get('system.param_sep') . urlencode(implode(' ', $this->query));
                    }
                }
                 $this->pagination_details['base_url'] = $base_pagination_url;


                $twig->twig_vars['pagination'] = $this->pagination_details;
            } elseif (!$is_ajax_results_request && !$this->pagination_details && $this->query) {
                // Case where query was made, but no results, still provide empty pagination structure for Twig
                 $twig->twig_vars['pagination'] = [
                    'total_results' => 0,
                    'current_page' => 1,
                    'per_page' => (int)$this->config->get('plugins.simplesearch.per_page', 10),
                    'total_pages' => 0,
                    'base_url' => $this->grav['page']->url()
                ];
            }
        }

        if ($this->config->get('plugins.simplesearch.built_in_css')) {
            $this->grav['assets']->add('plugin://simplesearch/css/simplesearch.css');
        }

        if ($this->config->get('plugins.simplesearch.built_in_js')) {
            $this->grav['assets']->addJs('plugin://simplesearch/js/simplesearch.js', ['group' => 'bottom']);
        }
    }

    /**
     * @param array $array
     * @param array|null $ignore_keys
     * @param int $level
     * @return string
     */
    protected function getArrayValues($array, $ignore_keys = null, $level = 0) {
        $output = '';

        // Header values can be arbitrary objects injected by other plugins via
        // Page::modifyHeader(); bail out rather than recurse a cyclic graph.
        if ($level > 16) {
            return $output;
        }

        if (is_null($ignore_keys)) {
            $config = $this->config();
            $ignore_keys = $config['header_keys_ignored'] ?? ['title', 'taxonomy','content', 'form', 'forms', 'media_order'];
        }
        foreach ($array as $key => $child) {

            if ($level === 0 && in_array($key, $ignore_keys, true)) {
                continue;
            }

            if (is_object($child) && method_exists($child, '__toString')) {
                $output .= " " . $child;
            } elseif (is_array($child) || is_object($child)) {
                $output .= " " . $this->getArrayValues((array) $child, $ignore_keys, $level + 1);
            } elseif (is_scalar($child)) {
                $output .= " " . $child;
            }

        }
        return trim($output);
    }

    /**
     * Handles AJAX requests for search suggestions.
     *
     * @param Event $event
     * @return void
     */
    public function onAjaxSearchSuggestions(Event $event)
    {
        // Ensure this is an AJAX request
        if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
            // Not an AJAX request
            return;
        }

        $query_param = trim(strtolower($this->grav['uri']->param('query', $this->grav['uri']->query('query','')))); // Also check actual query
        $min_query_length = $this->config->get('plugins.simplesearch.min_query_length_suggestions', 3);

        if (strlen($query_param) < $min_query_length) {
            header('Content-Type: application/json');
            echo json_encode([]);
            exit;
        }

        $suggestions = [];
        $max_suggestions = $this->config->get('plugins.simplesearch.max_suggestions', 5);

        $this->grav['pages']->enablePages(); // Ensure pages are loaded
        $pages = $this->grav['pages']->all();
        $pages->published()->routable();

        foreach ($pages as $page) {
            if (count($suggestions) >= $max_suggestions) {
                break;
            }

            // Using a simplified match for titles
            if ($this->matchText(strip_tags($page->title()), $query_param) !== false) {
                $suggestions[] = [
                    // For suggestions, we usually don't highlight, but if we wanted to:
                    // 'title' => $this->highlightQueryTerms($page->title(), [$query_param]),
                    'title' => $page->title(),
                    'url' => $page->url(),
                ];
            }
        }

        header('Content-Type: application/json');
        echo json_encode($suggestions);
        exit;
    }

    /**
     * Highlights search terms in a given text string.
     *
     * @param string $text The text to highlight.
     * @param array|string $query_terms The search term(s) as an array or a single string.
     * @param string $tag The HTML tag to wrap around highlighted terms.
     * @return string The text with search terms highlighted.
     */
    private function highlightQueryTerms($text, $query_terms, $tag = 'mark') {
        if (empty($query_terms) || empty(trim((string)$text))) {
            return $text;
        }
        if (!is_array($query_terms)) {
            $query_terms = [$query_terms];
        }

        foreach ($query_terms as $term) {
            $term = trim($term);
            if (empty($term)) {
                continue;
            }
            
            $escapedTerm = preg_quote($term, '/');
            // Regex:
            // (?![^<]*?>)  -- Negative lookahead: ensures we are not inside an HTML tag's attributes. Not foolproof for all HTML.
            // ( ... )       -- Capturing group for the term itself.
            // /ui           -- Case-insensitive (u) and Unicode (u) flags.
            $text = preg_replace('/(?![^<]*?>)(' . $escapedTerm . ')/ui', "<{$tag}>$1</{$tag}>", (string)$text);
        }
        return $text;
    }
}
