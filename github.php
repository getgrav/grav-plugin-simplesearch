<?php
namespace Grav\Plugin;

use \Grav\Common\Plugin;
use \Grav\Common\Page\Page;
use \Grav\Common\Page\Pages;
use \Grav\Common\Grav;
use \Grav\Common\Uri;
use \Grav\Common\Filesystem\File;
use \Grav\Common\Twig;
use \Grav\Plugin\Form;
use Tracy\Debugger;

class GitHubPlugin extends Plugin
{
    protected $active = false;
    protected $github;

    /**
     * @return array
     */
    public static function getSubscribedEvents() {
        return [
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onPageInitialized' => ['onPageInitialized', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
        ];
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Initialize github when detected in a page.
     */
    public function onPageInitialized()
    {

        $page = $this->grav['page'];
        if (!$page) {
            return;
        }

        if (isset($page->header()->github)) {
            $this->active = true;

            // Initialize GitHub API Class
            require_once __DIR__ . '/classes/github.php';
            $this->github = new GitHub($page);

        }
    }


    /**
     * Make form accessible from twig.
     */
    public function onTwigSiteVariables()
    {
        if (!$this->active) {
            return;
        }
        // in Twig template: {{ github.client.api('repo').show('getgrav', 'grav')['stargazers_count'] }}
        $this->grav['twig']->twig_vars['github'] = $this->github;
    }
}
