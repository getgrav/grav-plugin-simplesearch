# Grav SimpleSearch Plugin

`SimpleSearch` is a simple, yet very powerful [Grav][grav] plugin that adds search capabilities to your Grav instance.

# Installation

To install this plugin, just download the zip version of this repository and unzip it under `/your/site/grav/user/plugins`. Then, rename the folder to `simplesearch`.

You should now have all the plugin files under

	/your/site/grav/user/plugins/simplesearch

>> NOTE: This plugin is a modular component for Grav which requires [Grav](http://github.com/getgrav/grav), the [Error](https://github.com/getgrav/grav-plugin-error) and [Problems](https://github.com/getgrav/grav-plugin-problems) plugins, and a theme to be installed in order to operate.

# Usage

`SimpleSearch` creates a **route** that you define and based on the **taxonomy** filters generates a search result.

To make a search call, this is the URL you would go to:

```
http://yoursite.com/search/query:something
```

1. `/search`: This is the **route** setting and it can be changed
2. `/query:something`: This is the query itself, where `something` is what you are searching for.

After installing the SimpleSearch plugin, you can add a simple **searchbox** to your site by including the provided twig template.  Or copy it from the plugin to your theme and customize it as you please:

```
{% include 'partials/simplesearch_searchbox.html.twig' %}
```

To customize the plugin, you frst need to create an override config. To do so, create the folder `user/config/plugins` (if it doesn't exist already) and copy the [simplesearch.yaml][simplesearch] config file in there.

You can also completely customize the look and feel of the results by overriding the template. There are two methods to do this.

1. Copy the file [templates/simplesearch_results.html.twig][results] under your theme templates `user/themes/_your-theme_/templates/` and customize it.


2. Create your very own results output. For this you need to change the `template` reference in the config (let's say **mysearch_results**). In your theme you would then create the new template under `user/themes/_your-theme_/templates/mysearch_results.html.twig` and write your customizations. This is how it looks by default:

    ```
    {% extends 'partials/simplesearch_base.html.twig' %}

    {% block content %}
        <div class="content-padding">
        <h1 class="search-header">Search Results</h1>
        <h3>Query: "{{ query }}" - Found {{ search_results.count }} {{ 'Item'|pluralize(search_results.count) }}</h3>

        {% for page in search_results %}
            {% include 'partials/simplesearch_item.html.twig' with {'page':page} %}
        {% endfor %}
        </div>
    {% endblock %}
    ```

For further help with the `filters` settings, please refer to our [Taxonomy][taxonomy] and [Headers][headers] documentation.

# Config Defaults

```
route: /search
template: simplesearch_results
filters:
    category: blog
```

[taxonomy]: http://learn.getgrav.org/content/taxonomy
[headers]: http://learn.getgrav.org/content/headers
[grav]: http://github.com/getgrav/grav
[simplesearch]: simplesearch.yaml
[results]: templates/simplesearch_results.html.twig
