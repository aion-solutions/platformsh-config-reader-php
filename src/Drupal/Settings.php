<?php

declare(strict_types = 1);

namespace Platformsh\ConfigReader\Drupal;

use Composer\Autoload\ClassLoader;
use Platformsh\ConfigReader\Config;
use Platformsh\ConfigReader\Helper;
use Platformsh\ConfigReader\NotValidPlatformException;

/**
 * Provides helpers to handle Platform.sh hosting.
 *
 * This class should only be used from settings.php file.
 */
class Settings {

  private Config $config;

  private ?array $databases;

  private array $settings;

  private ClassLoader $classLoader;

  private bool $isPlatformShApplication;

  public function __construct(?array &$databases, array &$settings, ClassLoader $class_loader) {
    $this->databases = &$databases;
    $this->settings = &$settings;
    $this->classLoader = $class_loader;
    $this->config = Helper::getConfig();

    try {
      $this->isPlatformShApplication = !empty($this->config->application());
    }
    catch (NotValidPlatformException $e) {
      $this->isPlatformShApplication = FALSE;
    }
  }

  /**
   * Check if the application is running on the Platform.sh hosting platform.
   *
   * @return bool Returns true if the application is running on Platform.sh,
   *   false otherwise.
   */
  public function isPlatformShApplication(): bool {
    return $this->isPlatformShApplication;
  }

  public function applyDefaultSettings(): void {
    if ($this->isPlatformShApplication()) {
      $this->defineDatabase();
      $this->setErrorMessageVerbosity();
      $this->configureRedis();
      $this->setApplicationEnvironment();
      $this->setTempAndPrivateFilePaths();
      $this->setPhpStorage();
      $this->setHashSalt();
      $this->setDeploymentIdentifier();
      $this->setTrustedHostPatterns();
      $this->setDrupalVariables();
    }
  }

  public function defineDatabase(string $relationship = 'database', string $key = 'default', string $target = 'default'): void {
    if ($this->config->hasRelationship($relationship)) {
      $credentials = $this->config->credentials($relationship);
      $this->databases[$key][$target] = [
        'driver' => $credentials['scheme'],
        'database' => $credentials['path'],
        'username' => $credentials['username'],
        'password' => $credentials['password'],
        'host' => $credentials['host'],
        'port' => $credentials['port'],
        'pdo' => [\PDO::MYSQL_ATTR_COMPRESS => !empty($credentials['query']['compression'])],
        'init_commands' => [
          'isolation_level' => 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED',
        ],
      ];
    }
  }

  /**
   * Define error message verbosity.
   *
   * Enable verbose error messages on development branches, but not on the
   * production branch.
   * You may add more debug-centric settings here if desired to have them
   * automatically enable on development but not production.
   */
  public function setErrorMessageVerbosity(): void {
    global $config;

    if (isset($this->config->branch)) {
      // Production type environment.
      if ($this->config->onProduction() || $this->config->onDedicated()) {
        $config['system.logging']['error_level'] = 'hide';
      }
      // Development type environment.
      else {
        $config['system.logging']['error_level'] = 'verbose';
      }
    }
  }

  /**
   * Defines the APP_ENV environment variable for backward compatibility.
   */
  public function setApplicationEnvironment(): void {
    if ($this->config->inRuntime()) {
      if (!getenv('APP_ENV')) {
        putenv('APP_ENV=' . getenv('PLATFORM_ENVIRONMENT_TYPE'));
      }
    }
  }

  /**
   * Configure private and temporary file paths.
   */
  public function setTempAndPrivateFilePaths(): void {
    if ($this->config->inRuntime()) {
      if (!isset($settings['file_private_path'])) {
        $this->settings['file_private_path'] = $this->config->appDir . '/private';
      }

      if (!isset($settings['file_temp_path'])) {
        $this->settings['file_temp_path'] = $this->config->appDir . '/tmp';
      }
    }
  }

  /**
   * Configure the default PhpStorage and Twig template cache directories.
   */
  public function setPhpStorage(): void {
    if ($this->config->inRuntime()) {
      $dir = $this->settings['file_private_path'] ?? $this->config->appDir . '/private';

      if (!isset($settings['php_storage']['default'])) {
        $this->settings['php_storage']['default']['directory'] = $dir;
      }

      if (!isset($settings['php_storage']['twig'])) {
        $this->settings['php_storage']['twig']['directory'] = $dir;
      }
    }
  }

  /**
   * Set the project-specific entropy value.
   *
   * This is used for generating one-time keys and such.
   */
  public function setHashSalt(): void {
    if ($this->config->inRuntime() && empty($this->settings['hash_salt'])) {
      $this->settings['hash_salt'] = $this->config->projectEntropy;
    }
  }

  /**
   * Set the deployment identifier, which is used by some Drupal cache systems.
   */
  public function setDeploymentIdentifier(): void {
    if ($this->config->inRuntime()) {
      $this->settings['deployment_identifier'] = $this->settings['deployment_identifier'] ?? $this->config->treeId;
    }
  }

  /**
   * Overrides the "trusted_host_patterns" value.
   *
   * The 'trusted_hosts_pattern' setting allows an admin to restrict the Host
   * header values that are considered trusted. If an attacker sends a request
   * with a custom-crafted Host header then it can be an injection vector,
   * depending on how the Host header is used.
   * However, Platform.sh already replaces the Host header with the route that
   * was used to reach Platform.sh, so it is guaranteed to be safe. The
   * following method explicitly allows all Host headers, as the only possible
   * Host header is already guaranteed safe.
   */
  public function setTrustedHostPatterns(): void {
    $this->settings['trusted_host_patterns'] = ['.*'];
  }

  /**
   * Set the Drupal variables.
   *
   * Variables prefixed with 'drupalsettings:' are imported into $settings and
   * those prefixed with 'drupalconfig:' into $config.
   */
  public function setDrupalVariables(): void {
    global $config;

    foreach ($this->config->variables() as $name => $value) {
      $parts = explode(':', $name);
      [$prefix, $key] = array_pad($parts, 3, null);
      switch ($prefix) {
        // Variables that begin with `drupalsettings` or `drupal` get mapped
        // to the $settings array verbatim, even if the value is an array.
        // For example, a variable named drupalsettings:example-setting' with
        // value 'foo' becomes $settings['example-setting'] = 'foo';
        case 'drupalsettings':
        case 'drupal':
          $this->settings[$key] = $value;
          break;
        // Variables that begin with `drupalconfig` get mapped to the $config
        // array.  Deeply nested variable names, with colon delimiters,
        // get mapped to deeply nested array elements. Array values
        // get added to the end just like a scalar. Variables without
        // both a config object name and property are skipped.
        // Example: Variable `drupalconfig:conf_file:prop` with value `foo` becomes
        // $config['conf_file']['prop'] = 'foo';
        // Example: Variable `drupalconfig:conf_file:prop:subprop` with value `foo` becomes
        // $config['conf_file']['prop']['subprop'] = 'foo';
        // Example: Variable `drupalconfig:conf_file:prop:subprop` with value ['foo' => 'bar'] becomes
        // $config['conf_file']['prop']['subprop']['foo'] = 'bar';
        // Example: Variable `drupalconfig:prop` is ignored.
        case 'drupalconfig':
          if (count($parts) > 2) {
            $temp = &$config[$key];
            foreach (array_slice($parts, 2) as $n) {
              $prev = &$temp;
              $temp = &$temp[$n];
            }
            $prev[$n] = $value;
          }
          break;
      }
    }
  }

  /**
   * Enable Redis caching if applicable.
   *
   * @param string $relationship The relationship identifier for the Redis
   *   configuration. Defaults to 'redis'.
   */
  public function configureRedis(string $relationship = 'redis'): void {
    if ($this->config->hasRelationship($relationship) && class_exists('Drupal\Core\Installer\InstallerKernel') && !\Drupal\Core\Installer\InstallerKernel::installationAttempted() && extension_loaded('redis') && class_exists('Drupal\redis\ClientFactory')) {
      $redis = $this->config->credentials($relationship);

      // Set Redis as the default backend for any cache bin not otherwise specified.
      $this->settings['cache']['default'] = 'cache.backend.redis';
      $this->settings['redis.connection']['host'] = $redis['host'];
      $this->settings['redis.connection']['port'] = $redis['port'];

      // Apply changes to the container configuration to better leverage Redis.
      // This includes using Redis for the lock and flood control systems, as well
      // as the cache tag checksum. Alternatively, copy the contents of that file
      // to your project-specific services.yml file, modify as appropriate, and
      // remove this line.
      $this->settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';

      // Allow the services to work before the Redis module itself is enabled.
      $this->settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';

      // Manually add the classloader path, this is required for the container cache bin definition below
      // and allows to use it without the redis module being enabled.
      $this->classLoader->addPsr4('Drupal\\redis\\', 'modules/contrib/redis/src');

      // Use redis for container cache.
      // The container cache is used to load the container definition itself, and
      // thus any configuration stored in the container itself is not available
      // yet. These lines force the container cache to use Redis rather than the
      // default SQL cache.
      $this->settings['bootstrap_container_definition'] = [
        'parameters' => [],
        'services' => [
          'redis.factory' => [
            'class' => 'Drupal\redis\ClientFactory',
          ],
          'cache.backend.redis' => [
            'class' => 'Drupal\redis\Cache\CacheBackendFactory',
            'arguments' => ['@redis.factory', '@cache_tags_provider.container', '@serialization.phpserialize'],
          ],
          'cache.container' => [
            'class' => '\Drupal\redis\Cache\PhpRedis',
            'factory' => ['@cache.backend.redis', 'get'],
            'arguments' => ['container'],
          ],
          'cache_tags_provider.container' => [
            'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
            'arguments' => ['@redis.factory'],
          ],
          'serialization.phpserialize' => [
            'class' => 'Drupal\Component\Serialization\PhpSerialize',
          ],
        ],
      ];
    }
  }

  /**
   * Default configuration for Symfony Mailer and for the transport id giver.
   *
   * @param string $transportId
   */
  public function configureSymfonyMailer(string $transportId = 'smtp'): void {
    global $config;

    if ($this->isPlatformShApplication() && $this->config->inRuntime()) {
      $config['symfony_mailer.mailer_transport.' . $transportId]['configuration']['user'] = '';
      $config['symfony_mailer.mailer_transport.' . $transportId]['configuration']['pass'] = '';
      $config['symfony_mailer.mailer_transport.' . $transportId]['configuration']['host'] = getenv('PLATFORM_SMTP_HOST');
      $config['symfony_mailer.mailer_transport.' . $transportId]['configuration']['port'] = '25';
    }
  }

}
