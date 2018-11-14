<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Database\DB;
use App\Web\Session;
use Slim\App;
use Slim\Container;
use Slim\Http\Request;
use Slim\Http\Response;

define('PLATFORM_VERSION', json_decode(file_get_contents(__DIR__ . '/../composer.json'))->version);

$config = [
	'base_url' => isset($_SERVER['HTTPS']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'http://' . $_SERVER['HTTP_HOST'],
	'storage_dir' => 'storage',
	'displayErrorDetails' => true,
	'db' => [
		'connection' => 'sqlite',
		'dsn' => 'resources/database/xbackbone.db',
		'username' => null,
		'password' => null,
	],
];

$container = new Container(['settings' => $config]);

Session::init('xbackbone_session');

$container['view'] = function ($container) use (&$config) {
	$view = new \Slim\Views\Twig(__DIR__ . '/templates', [
		'cache' => false,
		'autoescape' => 'html',
		'debug' => $config['displayErrorDetails'],
		'auto_reload' => $config['displayErrorDetails'],
	]);

	// Instantiate and add Slim specific extension
	$router = $container->get('router');
	$uri = \Slim\Http\Uri::createFromEnvironment(new \Slim\Http\Environment($_SERVER));
	$view->addExtension(new Slim\Views\TwigExtension($router, $uri));

	$view->getEnvironment()->addGlobal('config', $config);
	$view->getEnvironment()->addGlobal('request', $container->get('request'));
	$view->getEnvironment()->addGlobal('alerts', Session::getAlert());
	$view->getEnvironment()->addGlobal('session', Session::all());
	$view->getEnvironment()->addGlobal('PLATFORM_VERSION', PLATFORM_VERSION);
	return $view;
};

function migrate($config)
{
	$firstMigrate = false;
	if ($config['db']['connection'] === 'sqlite' && !file_exists(__DIR__ . '/../' . $config['db']['dsn'])) {
		touch(__DIR__ . '/../' . $config['db']['dsn']);
		$firstMigrate = true;
	}

	try {
		DB::query('SELECT 1 FROM `migrations` LIMIT 1');
	} catch (PDOException $exception) {
		$firstMigrate = true;
	}

	if ($firstMigrate) {
		DB::raw()->exec(file_get_contents(__DIR__ . '/../resources/schemas/migrations.sql'));
	}

	$files = glob(__DIR__ . '/../resources/schemas/' . DB::driver() . '/*.sql');

	$names = array_map(function ($path) {
		return basename($path);
	}, $files);

	$in = str_repeat('?, ', count($names) - 1) . '?';

	$inMigrationsTable = DB::query("SELECT * FROM `migrations` WHERE `name` IN ($in)", $names)->fetchAll();


	foreach ($files as $file) {

		$continue = false;
		$exists = false;

		foreach ($inMigrationsTable as $migration) {
			if (basename($file) === $migration->name && $migration->migrated) {
				$continue = true;
				break;
			} else if (basename($file) === $migration->name && !$migration->migrated) {
				$exists = true;
				break;
			}
		}
		if ($continue) continue;

		$sql = file_get_contents($file);
		try {
			DB::raw()->exec($sql);
			if (!$exists) {
				DB::query('INSERT INTO `migrations` VALUES (?,?)', [basename($file), 1]);
			} else {
				DB::query('UPDATE `migrations` SET `migrated`=? WHERE `name`=?', [1, basename($file)]);
			}
		} catch (PDOException $exception) {
			if (!$exists) {
				DB::query('INSERT INTO `migrations` VALUES (?,?)', [basename($file), 0]);
			}
			throw $exception;
		}
	}
}

$app = new App($container);

$app->get('/', function (Request $request, Response $response) {

	$installed = file_exists(__DIR__ . '/../config.php');

	return $this->view->render($response, 'install.twig', ['installed' => $installed]);
});

$app->post('/', function (Request $request, Response $response) use (&$config) {
	$installed = true;
	if (!file_exists(__DIR__ . '/../config.php')) {
		$installed = false;

		$config['base_url'] = $request->getParam('base_url');
		$config['storage_dir'] = $request->getParam('storage_dir');
		$config['displayErrorDetails'] = false;
		$config['db']['connection'] = $request->getParam('connection');
		$config['db']['dsn'] = $request->getParam('dsn');
		$config['db']['username'] = $request->getParam('db_user');
		$config['db']['password'] = $request->getParam('db_password');


		file_put_contents(__DIR__ . '/../config.php', '<?php' . PHP_EOL . 'return ' . var_export($config, true) . ';');
	}

	$dsn = $config['db']['connection'] === 'sqlite' ? __DIR__ . '/../' . $config['db']['dsn'] : $config['db']['dsn'];

	DB::setDsn($config['db']['connection'] . ':' . $dsn, $config['db']['username'], $config['db']['password']);

	migrate($config);

	if (!$installed) {
		DB::query("INSERT INTO `users` (`email`, `username`, `password`, `is_admin`, `user_code`) VALUES (?, 'admin', ?, 1, ?)", [$request->getParam('email'), password_hash($request->getParam('password'), PASSWORD_DEFAULT), substr(md5(microtime()), rand(0, 26), 5)]);
	}

	cleanDirectory(__DIR__ . '/../resources/cache');
	cleanDirectory(__DIR__ . '/../resources/sessions');

	return $response->withRedirect('../?afterInstall=true');
});

$app->run();