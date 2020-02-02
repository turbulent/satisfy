<?php

namespace Playbloom\Satisfy\Controller;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Composer\Satis\Console\Application as SatisApp;

/**
 * Webhook controller provider.
 */
class WebhookController implements ControllerProviderInterface
{
    const SUPPORTED_WEBHOOK_EVENTS = ['push', 'tag_push'];

    /**
     * Connect
     *
     * @param  Application $app
     *
     * @return ControllerCollection
     */
    public function connect(Application $app)
    {
        $controllers = $app['controllers_factory'];

	$buildRepo = function ($url) {
                // Apache unsets the home directory in the ENV when booting up,
                // so we have to re-establish it or Composer will complain.
                // See: /etc/apache2/envvars
		putenv('HOME=/var/www');

                $args = [
                        'command' => 'build',
                        'file' => '/var/www/satisfy/satis.json',
                        'output-dir' => '/var/www/html',
                        '--repository-url' => $url,
                ];

                $input = new ArrayInput($args);
                $output = new ConsoleOutput();
                $satisApp = new SatisApp();
                return $satisApp->run($input, $output);
	};

	$findRepo = function($group, $project) use ($app) {
		$repositories = $app['satis']->findAllRepositories();
		$repositoryUrl = null;
		
		foreach ($repositories as $repo) {
			$url = $repo->getUrl();
			if (strpos($url, "$group/$project") !== false) {
				$repositoryUrl = $url;
				break;
			}
		}
		
		return $repositoryUrl;
	};

	$controllers->get('/rebuild/{group}/{project}', function (Request $request, $group, $project) use ($app, $buildRepo, $findRepo) {
		$repositoryUrl = $findRepo($group, $project);
		if (!$repositoryUrl) {
			return $app->json("Repository matching $group/$project not found", 404);	
		}

		$returnCode = $buildRepo($repositoryUrl);
		return $app->json("Return code: $returnCode", 200);
	})
	->bind('webhook_rebuild');

	$controllers->post('/webhook/gitlab', function (Request $request) use ($app, $buildRepo, $findRepo) { 
		$gitlabSecretToken = $request->headers->get('X-Gitlab-Token');
		$data = json_decode($request->getContent(), true);
		$eventType = isset($data['object_kind']) ? $data['object_kind'] : null;
		$group = isset($data['project']['namespace']) ? $data['project']['namespace'] : null;
		$projectName = isset($data['project']['name']) ? $data['project']['name'] : null;

		if ($gitlabSecretToken !== $app['gitlab-secret-token']) {
			$msg = 'Bad token';
			error_log($msg);
			return $app->json($msg, 400);
		}

		if (!in_array($eventType, self::SUPPORTED_WEBHOOK_EVENTS)) {
			$msg = "Unsupported webhook event \"$eventType\", ignoring";
			error_log($msg);
			return $app->json($msg, 200);
		}

		if (empty($eventType)) {
			$msg = 'Missing webhook event type';
			error_log($msg);
			return $app->json($msg, 400);
		}

		if (empty($group) || empty($projectName)) {
			$msg = 'Missing project namespace (group) or project name';
			error_log($msg);
			return $app->json($msg, 400);
		}

		error_log("Webhook event \"$eventType\" for repository $group/$projectName");

		$repositoryUrl = $findRepo($group, $projectName);
		if (!$repositoryUrl) {
			$msg = "Repository $group/$projectName is not being tracked by Satis, ignoring";
			error_log($msg);
			return $app->json($msg, 200);
		}

		$buildRepo($repositoryUrl);

		return $app->json('OK', 200);
	})
	->bind('webhook_gitlab');

        return $controllers;
    }
}
