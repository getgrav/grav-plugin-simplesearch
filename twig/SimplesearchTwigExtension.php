<?php

declare(strict_types=1);

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig Extension for SimpleSearch highlighting and excerpts
 */
class SimplesearchTwigExtension extends AbstractExtension
{
    /**
     * Return extension name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'SimplesearchTwigExtension';
    }

    /**
     * Register Twig filters
     *
     * @return array<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('highlight', [$this, 'highlightFilter'], ['is_safe' => ['html']]),
            new TwigFilter('excerpt', [$this, 'excerptFilter']),
        ];
    }

    /**
     * Register Twig functions
     *
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('search_highlight', [$this, 'highlightText'], ['is_safe' => ['html']]),
            new TwigFunction('search_excerpt', [$this, 'extractExcerpt']),
        ];
    }

    /**
     * Highlight search terms in text (Twig filter)
     *
     * @param string $text Text to highlight
     * @param string $query Search query
     * @param string $class CSS class for highlighting
     * @return string Text with highlighted terms
     */
    public function highlightFilter(string $text, string $query = '', string $class = 'search-highlight'): string
    {
        return $this->highlightText($text, $query, $class);
    }

    /**
     * Extract excerpt with search query context (Twig filter)
     *
     * @param string $text Full text
     * @param string $query Search query
     * @param int $length Excerpt length
     * @return string Excerpt
     */
    public function excerptFilter(string $text, string $query = '', int $length = 200): string
    {
        return $this->extractExcerpt($text, $query, $length);
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

        // Handle multiple comma-separated queries
        $queries = array_filter(array_map('trim', explode(',', $query)));

        foreach ($queries as $q) {
            $pattern = '/(' . preg_quote($q, '/') . ')/ui';
            $replacement = '<mark class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">$1</mark>';
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
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

        if (empty($query)) {
            return mb_substr($text, 0, $length) . (mb_strlen($text) > $length ? '...' : '');
        }

        // Try first query term if multiple
        $queries = array_filter(array_map('trim', explode(',', $query)));
        $first_query = $queries[0] ?? '';

        $query_lower = mb_strtolower($first_query);
        $text_lower = mb_strtolower($text);

        $pos = mb_strpos($text_lower, $query_lower);

        if ($pos === false) {
            // Query not found, return beginning
            return mb_substr($text, 0, $length) . (mb_strlen($text) > $length ? '...' : '');
        }

        // Center the excerpt around the query
        $half_length = (int)($length / 2);
        $start = max(0, $pos - $half_length);

        // Adjust start to word boundary
        if ($start > 0) {
            $space_pos = mb_strpos($text, ' ', $start);
            if ($space_pos !== false && $space_pos < $start + 20) {
                $start = $space_pos + 1;
            }
        }

        $excerpt = mb_substr($text, $start, $length);

        // Adjust end to word boundary
        if (($start + $length) < mb_strlen($text)) {
            $last_space = mb_strrpos($excerpt, ' ');
            if ($last_space !== false) {
                $excerpt = mb_substr($excerpt, 0, $last_space);
            }
        }

        $prefix = $start > 0 ? '...' : '';
        $suffix = ($start + mb_strlen($excerpt)) < mb_strlen($text) ? '...' : '';

        return $prefix . $excerpt . $suffix;
    }
}
