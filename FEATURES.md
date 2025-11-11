# SimpleSearch Enhanced Features

## 🚀 What's New

This enhanced version of SimpleSearch transforms the plugin into a modern, powerful search solution for Grav CMS with advanced features comparable to professional search engines.

---

## ⭐ Core Improvements

### 1. **Intelligent Relevance Scoring**
- **Smart Ranking Algorithm**: Results are now ranked by relevance, not just date
- **Multi-factor Scoring**:
  - Title matches: 10x weight
  - Taxonomy matches: 5x weight
  - Header matches: 3x weight
  - Content matches: 1x weight
- **Contextual Scoring**:
  - Exact phrase matches get highest scores
  - Word boundary matches are prioritized
  - Match density and frequency considered
  - Early occurrence bonus for matches at the beginning
- **Visual Relevance Indicators**: See relevance bars in search results

### 2. **Advanced Pagination**
- **Configurable Results Per Page**: Set `results_per_page` in config (default: 10)
- **Smart Pagination Controls**:
  - Previous/Next navigation
  - Page number links with intelligent truncation
  - Current page highlighting
  - Jump to first/last page
  - Mobile-responsive pagination
- **Result Count Display**: "Showing 1-10 of 234 results"
- **URL-based Navigation**: Clean URLs with page parameter

### 3. **Search Term Highlighting**
- **Context-Aware Highlighting**: Search terms are highlighted in:
  - Page titles
  - Excerpts
  - Content snippets
- **Multiple Query Support**: All comma-separated search terms are highlighted
- **Smart Excerpts**:
  - Automatically extracts relevant text around search terms
  - Configurable excerpt length (`excerpt_length` setting)
  - Word-boundary aware truncation
  - Centered on query matches
- **Twig Filters**: Easy-to-use filters for custom templates
  ```twig
  {{ page.title|highlight(query)|raw }}
  {{ page.content|excerpt(query, 200)|highlight(query)|raw }}
  ```

---

## 🎨 Modern User Interface

### 4. **Responsive Design**
- **Mobile-First Approach**: Works perfectly on all devices
- **Tablet Optimized**: Grid layouts adapt to screen size
- **Touch-Friendly**: Large tap targets for mobile users
- **Print-Friendly**: Optimized print styles included

### 5. **Dark Mode Support**
- **Automatic Detection**: Respects user's system preferences
- **Manual Override**: Support for `data-theme="dark"` attribute
- **CSS Variables**: Easy customization of colors
- **High Contrast**: Ensures readability in both modes
- **Smooth Transitions**: Seamless theme switching

### 6. **Enhanced Accessibility**
- **ARIA Support**:
  - Proper roles and labels
  - Screen reader announcements
  - Semantic HTML structure
- **Keyboard Navigation**:
  - Tab through all interactive elements
  - Arrow keys for pagination and autocomplete
  - Enter to select, Escape to cancel
- **Focus Management**: Visible focus indicators
- **Screen Reader Optimized**:
  - Live regions for dynamic content
  - Descriptive labels
  - Status announcements

---

## ⚡ Advanced Features

### 7. **Instant Search (Live Search)**
- **Search-as-You-Type**: See results while typing
- **Debounced Requests**: Optimized to reduce server load (300ms delay)
- **Quick Results Preview**: Show top 5 results instantly
- **JSON API**: Lightweight AJAX requests
- **Loading Indicators**: Visual feedback during search
- **Configure**: Set `instant_search: true` in config

### 8. **Intelligent Autocomplete**
- **Search Suggestions**: Smart suggestions as you type
- **Recent Searches**: Remembers your last 10 searches (localStorage)
- **Keyboard Navigation**: Arrow keys to navigate suggestions
- **Click or Enter to Select**: Multiple interaction methods
- **Cached Suggestions**: Fast response times
- **Configurable Max Results**: Set `autocomplete_max` option
- **Configure**: Set `autocomplete: true` in config

### 9. **Keyboard Shortcuts**
- **`Ctrl+K` or `Cmd+K`**: Focus search field (like GitHub)
- **`/`**: Quick focus to search (like Google)
- **`Escape`**: Clear and blur search field
- **Arrow Keys**: Navigate autocomplete and pagination
- **Enter**: Submit search or select suggestion
- **Tab**: Navigate through interface
- **Configure**: Set `keyboard_shortcuts: true` (enabled by default)

### 10. **Enhanced Search Input**
- **Clear Button**: One-click to clear search query
- **Loading Spinner**: Visual feedback during searches
- **Input Validation**: Real-time validation with custom messages
- **Autofocus**: Search input focused on results page
- **Placeholder Hints**: Helpful guidance for users
- **Character Counter**: Shows minimum character requirement

---

## 🔧 Technical Enhancements

### 11. **Modern JavaScript (ES6+)**
- **Class-Based Architecture**: Clean, maintainable code
- **Modular Design**: Easy to extend and customize
- **Event-Driven**: Efficient event handling
- **No jQuery Dependency**: Pure vanilla JavaScript
- **Async/Await**: Modern async patterns
- **localStorage Integration**: Client-side caching
- **Configurable Options**: Extensive customization

### 12. **PHP 7.1+ Strict Typing**
- **Type Safety**: Declare strict types throughout
- **Better Performance**: JIT compiler optimizations
- **Fewer Bugs**: Catch type errors early
- **Improved Documentation**: Clear parameter and return types
- **Modern Practices**: Following PHP best practices

### 13. **Twig Extensions**
- **Custom Filters**:
  - `highlight`: Highlight search terms in text
  - `excerpt`: Extract relevant excerpt from text
- **Custom Functions**:
  - `search_highlight()`: Function version of highlight filter
  - `search_excerpt()`: Function version of excerpt filter
- **Safe HTML Output**: Properly escaped and marked safe
- **Multi-query Support**: Handle comma-separated queries

### 14. **CSS Variables & Theming**
- **Easy Customization**: Change colors via CSS variables
- **Consistent Design**: Theme-aware components
- **Dark Mode Variables**: Separate color schemes
- **Flexible Layouts**: Grid and Flexbox based
- **Smooth Animations**: Hardware-accelerated transitions

---

## 📊 Performance Features

### 15. **Optimized Search Algorithm**
- **Efficient Scoring**: Minimal performance impact
- **Smart Caching**: Ready for cache implementation
- **Lazy Loading**: Load results only when needed
- **Debounced Input**: Reduce unnecessary searches
- **Minimal DOM Operations**: Optimized rendering

### 16. **Asset Loading**
- **Modern/Legacy Toggle**: Choose between modern or legacy assets
- **Conditional Loading**: Load only what's needed
- **Minification Ready**: Assets ready for production
- **CDN Compatible**: Can be served from CDN
- **Group Loading**: Assets loaded in proper order

---

## 🎯 Configuration Options

### New Configuration Options:

```yaml
# Modern Assets
use_modern_assets: true        # Use enhanced CSS/JS

# Relevance Sorting
relevance_sort: true           # Sort by relevance score

# Pagination
results_per_page: 10          # Results per page (0 = no pagination)
show_pagination_info: true    # Show "1-10 of 234"

# Display Options
show_excerpts: true           # Use smart excerpts
excerpt_length: 200           # Excerpt character length
show_relevance_score: true    # Show relevance indicator
show_result_count: true       # Show total result count

# Advanced Features
instant_search: false         # Enable live search
autocomplete: false           # Enable suggestions
keyboard_shortcuts: true      # Enable keyboard shortcuts

# Performance
cache_enabled: false          # Enable result caching
cache_lifetime: 3600         # Cache duration (seconds)

# API
enable_json_api: true         # Enable JSON endpoint
enable_autocomplete_api: false # Enable autocomplete endpoint
```

---

## 🔌 API Endpoints

### JSON Search Results
```
GET /search.json/query:your-search
```
Returns search results as JSON for AJAX requests.

### Autocomplete Suggestions (Future)
```
GET /search/autocomplete.json?q=your-query
```
Returns search suggestions as JSON.

---

## 🎨 Customization

### CSS Variables
Customize colors by overriding CSS variables:

```css
:root {
    --search-primary: #3b82f6;
    --search-primary-hover: #2563eb;
    --search-bg: #ffffff;
    --search-input-bg: #f9fafb;
    --search-text: #111827;
    /* ... and many more */
}
```

### Template Overrides
All templates can be overridden in your theme:
- `templates/simplesearch_results.html.twig`
- `templates/partials/simplesearch_item.html.twig`
- `templates/partials/simplesearch_searchbox.html.twig`

### JavaScript Customization
```javascript
window.simpleSearch = new SimpleSearch({
    instantSearch: true,
    autocomplete: true,
    minCharacters: 2,
    instantSearchDelay: 500,
    // ... more options
});
```

---

## 📱 Browser Support

- ✅ Chrome/Edge 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Opera 76+
- ✅ Mobile browsers (iOS Safari, Chrome Mobile)
- ⚠️ Legacy mode available for older browsers

---

## 🎓 Migration Guide

### From Legacy to Modern

1. **Backup your configuration**
2. **Update plugin**
3. **Set `use_modern_assets: true` in config**
4. **Test search functionality**
5. **Customize CSS variables if needed**
6. **Enable advanced features as desired**

### Backward Compatibility

All legacy features continue to work. New features are opt-in via configuration.

---

## 🐛 Troubleshooting

### Highlighting not working?
- Ensure Twig extension is loaded
- Check that `use_modern_assets: true`
- Clear Grav cache

### Pagination not showing?
- Set `results_per_page` > 0
- Ensure you have more results than the per-page limit

### Instant search not working?
- Enable `instant_search: true`
- Ensure JSON API is enabled
- Check browser console for errors

### Dark mode not applying?
- Check `prefers-color-scheme` support
- Or add `data-theme="dark"` to body

---

## 🚀 Performance Tips

1. **Use Raw Content Search**: Set `search_content: raw` for better performance
2. **Enable Caching**: When implemented, enable `cache_enabled: true`
3. **Limit Results**: Use pagination to reduce page load
4. **Optimize Images**: Use proper image sizes for thumbnails
5. **CDN Assets**: Serve CSS/JS from CDN in production

---

## 🤝 Contributing

Found a bug or want to add a feature? Contributions welcome!

---

## 📄 License

MIT License - Same as original SimpleSearch plugin

---

## 🙏 Credits

Enhanced by Claude AI based on the original SimpleSearch plugin by Team Grav.

**Original Plugin**: https://github.com/getgrav/grav-plugin-simplesearch
