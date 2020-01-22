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

	$controllers->post('/webhook/gitlab', function (Request $request) use ($app) {
		$data = json_decode($request->getContent(), true);
		$gitlabSecretToken = $request->headers->get('X-Gitlab-Token');
		$repositoryUrl = isset($data['project']['url']) ? $data['project']['url'] : null;
		$eventType = isset($data['object_kind']) ? $data['object_kind'] : null;

		error_log("Webhook event \"$eventType\" for repository $repositoryUrl");

		try {
			$repositoryId = md5($repositoryUrl);
			$repository = $app['satis']->findOneRepository($repositoryId);
		} catch (\Exception $e) {
			$msg = "Repository URL $repositoryUrl is not being tracked by Satis, ignoring";
			error_log($msg);
			return $app->json($msg, 200);
		}

		if ($gitlabSecretToken !== $app['gitlab-secret-token']) {
			$msg = 'Bad token';
			error_log($msg);
			return $app->json($msg, 400);
		}

		if (empty($repositoryUrl)) {
			$msg = 'Missing repository URL';
			error_log($msg);
			return $app->json($msg, 400);
		}

		if (empty($eventType)) {
			$msg = 'Missing webhook event type';
			error_log($msg);
			return $app->json($msg, 400);
		}

		if (!in_array($eventType, self::SUPPORTED_WEBHOOK_EVENTS)) {
			$msg = "Unsupported webhook event \"$eventType\", ignoring";
			error_log($msg);
			return $app->json($msg, 200);
		}

		// Apache unsets the home directory in the ENV when booting up,
		// so we have to re-establish it or Composer will complain.
		// See: /etc/apache2/envvars
		putenv('HOME=/var/www');	

		$args = [
			'command' => 'build',
			'file' => '/var/www/satisfy/satis.json',
			'output-dir' => '/var/www/html',
			'--repository-url' => $repositoryUrl,
		];

		$input = new ArrayInput($args);
		$output = new ConsoleOutput();
		$satisApp = new SatisApp();

		$satisApp->run($input, $output);

		return $app->json('OK', 200);
	})
	->bind('webhook_gitlab');

        return $controllers;
    }
}
