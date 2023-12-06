<a href="https://newfold.com/" target="_blank">
    <img src="https://newfold.com/content/experience-fragments/newfold/site-header/master/_jcr_content/root/header/logo.coreimg.svg/1621395071423/newfold-digital.svg" alt="Newfold Logo" title="Newfold Digital" align="right" 
height="42" />
</a>

# WP PLS Utility

WP PLS Utility is a PHP library that can be third-party code to save, activate, 
check and deactivate a license key against PLS server.

## Installation

### 1. Add the Newfold Satis to your `composer.json`

 ```bash
composer config repositories.newfold-labs composer https://newfold-labs.github.io/satis
 ```

### 2. Require the `newfold-labs/wp-pls-utility` package

 ```bash
composer require newfold-labs/wp-pls-utility
 ```

## Usage

If you want to change the default library config, you can call the static method `config` of the PLS class. 
It accepts an array of parameters like:
 - environment: the environment to use for the PLS server. `production` and `staging` are the accepted value (use one). Default value is `production`;
 - cache_ttl: the expiration time for the library cache system used by the `check` method. Default value is 12 hours;
 - timeout: the timeout for the HTTP request. Default value is 5 seconds.
 - network: set `true` to store license info as network options, `false` to use default options

Below is an example of how to use the config method:

```php
PLS::config(
    array(
        'environment' => 'staging',
        'cache_ttl' => 12 * HOUR_IN_SECONDS,
        'timeout' => 5,
        'network' => false
    )
);
```

Using the method `store_license_id` you can store a license ID for the given plugin slug.

```php
PLS::store_license_id( 'my-plugin-slug', 'efc1c3f0-2dcc-461a-9b6a-886dad6cab36' );
```
To activate the license against the PLS server, the `activate` method can be used. The `plugin_slug` parameter is mandatory, optionally you can pass the license ID to use and an array of arguments to use in the activation process.
By default, `domain_name` is the WordPress site home URL, `email` is the WordPress admin email.

```php
PLS::activate( 
    'my-plugin-slug' 
    'efc1c3f0-2dcc-461a-9b6a-886dad6cab36'
    array(
        'domain_name' => 'mysiteurl.com',
        'email' => 'myemail@email.com',
    )
);
```

To deactivate the license against the PLS server, the `deactivate` method can be used. The `plugin_slug` parameter is the only mandatory parameter.

```php
PLS::deactivate( 'my-plugin-slug' );
```

To check the license activation status, the `check` method can be used. The `plugin_slug` parameter is mandatory. Optionally you can pass true as a second parameter in order to refresh the cache.

```php
PLS::check( 'my-plugin-slug', true );
```