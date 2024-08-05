<?php

declare(strict_types = 1);

namespace Platformsh\ConfigReader;

/**
 * This class contains helper methods for Platform.sh hosting provider.
 */
class Helper {

  protected static Helper $instance;

  /**
   * @var \Platformsh\ConfigReader\Config
   */
  private Config $config;

  /**
   * Constructs a new instance of the class.
   */
  protected function __construct() {
    $this->config = new Config();
  }

  /**
   * Implements singleton pattern.
   *
   * @return static
   */
  public static function getInstance(): static {
    if (!isset(static::$instance)) {
      static::$instance = new static();
    }

    return static::$instance;
  }

  /**
   * Retrieves the configuration object.
   *
   * @return Config The configuration object.
   */
  public static function getConfig(): Config {
    return static::getInstance()->config;
  }

  /**
   * Get the site URL from the Platform.sh configuration, when applicable.
   *
   * @return string|null The site URL, or null.
   */
  public static function getSiteUrl(): ?string {
    $platformShConfig = static::getConfig();

    if (!$platformShConfig->inRuntime()) {
      return NULL;
    }

    $routes = $platformShConfig->getUpstreamRoutes($platformShConfig->applicationName);

    // Sort URLs, with the primary route first, then by HTTPS before HTTP, then by length.
    usort($routes, function (array $a, array $b) {
      // false sorts before true, normally, so negate the comparison.
      return
        [!$a['primary'], !str_starts_with($a['url'], 'https://'), strlen($a['url'])]
        <=>
        [!$b['primary'], !str_starts_with($b['url'], 'https://'), strlen($b['url'])];
    });

    // Return the url of the first one.
    return reset($routes)['url'] ?: NULL;
  }

}
