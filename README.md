# odavent-wordpress
WordPress plugin for Odavent: live speakers, agenda, sponsors and tickets from your Odavent events on your own WordPress site.


## Development

Tests run inside Docker, so no local PHP or Composer is needed (Docker is required). Tests run on PHP 7.4 by default (the plugin's floor), so PHP 8-only syntax fails locally; use `PHP_VERSION=8.3 bin/test` to run on another version:

```sh
bin/test              # run the whole PHPUnit suite
bin/test --filter PluginTest
```

The plugin targets PHP 7.4+ and WordPress 6.1+. CI lints every PHP file, runs PHPUnit on PHP 7.4 and 8.3, and checks PHP 7.4 compatibility with PHPCS (`PHPCompatibilityWP`, see `phpcs.xml.dist`).
