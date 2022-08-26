## Wix Posts to WordPress

> This is a class that allows you to import posts from Wix into WordPress.

## Requirements

- Please make sure you have the following installed:

  - https://github.com/mirzazeyrek/php-draftjs-html

`composer require 20minutes/php-draftjs-html`

- Wix API key

## Usage

```php
<?php

// autoload composer dependencies
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/class-wix-import.php';

// create WixBlogImport instance
$api_key = 'your-api-key';
$wix_blog_import = new WixBlogImport($api_key);

// import posts from Wix
$params = [
    'fieldsToInclude' => 'CONTENT', // Content field is required for successful import
    'paging' => [
        'limit' => 100,
        'offset' => 0,
    ]
];

$wix_blog_import->import($params);
```

## Hooks

#### WixBlogImport/insert_metas

```php
<?php

/**
 * Fires while inserting post metas.
 *
 * @param int $post_id Added Post ID
 * @param array $item Wix Post Item
 */
add_action('WixBlogImport/insert_metas', function ($post_id, $item) {
    // do something
});
```

#### WixBlogImport/after_insert_post

```php
<?php

/**
 * Fires after a post has been successfully inserted.
 *
 * @param int $post_id Added Post ID
 * @param array $item Wix Post Item
 */

add_action('WixBlogImport/after_insert_post', function ($post_id, $item) {
    // do something
});
```

#### WixBlogImport/post_data

```php
<?php

/**
 * Filters post data before inserting post.
 *
 * @param array $post_data Post data
 * @param array $item Wix Post Item
 */

add_filter('WixBlogImport/post_data', function ($post_data, $item) {
    // do something
    // maybe you want to change the post status before inserting the post.
    return $post_data;
});
```
