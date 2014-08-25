# Grav GitHub Plugin


`GitHub` is a [Grav][grav] Plugin that wraps the [GitHub v3 API][github-api].

The plugin itself relies on the [php-github-api][php-github-api] library  to wrap GitHub.

# Installation

To install this plugin, just download the zip version of this repository and unzip it under `/your/site/grav/user/plugins`. Then, rename the folder to `github`.

You should now have all the plugin files under

    /your/site/grav/user/plugins/github

>> NOTE: You might benefit from an additional layer of cache by using this plugin in combination with the [twigcache plugin][twigcache].

# Usage

To use `github`, you will need to trigger the plugin by adding the `github: true` setting in the header of the main page.

```
---
title: GitHub
github: true
---

# My GitHub page
```

You can use the `github` api from the _github.md_ file by enabling the _twig processing_ in the header, right where you added the `github: true`.

```
process:
    twig: true
```

The _github.md_ file itself, in return, will be loading the [_github.html.twig_][github.html.twig] that is provided by plugin and contains some examples.

Although if you use a template, or override the default one, it is more efficient and fast.

If you want to override the [_github.html.twig_][github.html.twig], copy the template file into the templates folder of your custom theme and that is it.

```
/your/site/grav/user/themes/custom-theme/templates/github.html.twig
```

You can now edit the override and tweak it to meet your needs.


# Examples
A few examples. Note that the APIs are based on [php-github-api][php-github-api] which uses the [GitHub v3 API][github-api]. Refer to their docs for additional informations.

### List _stargazers_ count of a repository
```
Grav has currently <strong>{{ github.client.api('repo').show('getgrav', 'grav')['stargazers_count'] }} stargazers</strong>
```

### List all the _repositories_ of a user
Lists all the repositories of a user and for each of them shows the link to GitHub, the forks count and the stargazers count.

```
<ul>
    <li>Repositories (<strong>{{ github.client.api('user').repositories('getgrav')|length }}</strong>):
        <ul>
        {% for repo in github.client.api('user').repositories('getgrav') %}
            <li>{{ repo.name|e }} [<a href="{{ repo.html_url|e }}">link</a> | forks: <strong>{{ repo.forks_count|e }}</strong> | stargazers: <strong>{{ repo.stargazers_count|e }}</strong>]</li>
        {% endfor %}
        </ul>
    </li>
</ul>
```

[grav]: http://github.com/getgrav/grav
[github-api]: https://developer.github.com/v3/
[php-github-api]: https://github.com/KnpLabs/php-github-api/
[twigcache]: https://github.com/getgrav/grav-plugin-twigcache
[github.html.twig]: templates/github.html.twig
