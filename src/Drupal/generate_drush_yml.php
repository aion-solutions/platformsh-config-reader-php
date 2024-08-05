<?php
/**
 * @file
 * A script that creates the .drush/drush.yml file.
 */

// This file should only be executed as a PHP-CLI script.
if (PHP_SAPI !== 'cli') {
  exit;
}

require_once(__DIR__ . '/../../../../autoload.php');

$appRoot = dirname(__DIR__);
$filename = $appRoot . '/drush/drush.yml';
$siteUrl = \Platformsh\ConfigReader\Helper::getSiteUrl();

if (empty($siteUrl)) {
  echo "Failed to find a site URL\n";

  if (file_exists($filename)) {
    echo "The file exists but may be invalid: $filename\n";
  }

  exit(1);
}

$siteUrlYamlEscaped = json_encode($siteUrl, JSON_UNESCAPED_SLASHES);
$scriptPath = __FILE__;

$success = file_put_contents($filename, <<<EOF
# Drush configuration file.
# This was automatically generated by the script:
# $scriptPath

options:
  # Set the default site URL.
  uri: $siteUrlYamlEscaped

EOF
);
if (!$success) {
  echo "Failed to write file: $filename\n";
  exit(1);
}

if (!chmod($filename, 0600)) {
  echo "Failed to modify file permissions: $filename\n";
  exit(1);
}

echo "Created Drush configuration file: $filename\n";
