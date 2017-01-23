# PowerSync
PowerSync is an awesome tool that sync database tables between WordPress installations.

#### How To Use: pratical example

```php

// Require class

require __DIR__.'/PowerSync.php';

// Init sync

PowerSync::init([
	'paths' => [
		'attachment_remote' => 'http://myonlinesite.com/media/', 	// Remote attachment url
		'wp_dir' => dirname(__DIR__).'/wordpress/'		// Wordpress directory position
	],
	'connections' => [
		'fdb_prms' => [					// Database "FROM" settings
			'host' => 'localhost',
			'name' => 'table_from',
			'user' => 'root',
			'pass' => ''
		],
		'tdb_prms' => [					// Database "TO" settings
			'host' => 'localhost',
			'name' => 'table_to',
			'user' => 'root',
			'pass' => ''
		]
	],
	'post_types' =>  [								// array of Post Types To Sync
		'mycustompost'	=> [						// post type id
			'import' => true,						// activate post type import | true/false
			'rewrite' => true,						// replace similar posts with imported post | true/false
			'date_priority' => false,				// import only recent post (date check)
			'taxonomies_update' => true,			// update taxonomies content
			'attachments' => ['_thumbnail_id'],		// array of ids attachments meta (es: photo, _thumbnail_id ecc)
			'taxonomies' => [						// array of taxonomies id (categories, my_awesome_cat, post_tags ecc)
				'mycustomcat' => [					// taxonomy id
					'meta' => [],					// array of meta of taxonomy to import (option_name table wp_options)
					'attachments' => [],			// array of meta attachments to import (option_name table wp_options)
					'rewrite_role' => '*_%id%'		// role to generate option_name. System substitute the * with meta or attachments option value and the %id% with the id of the tax. Example:  *_%id% => title_34 (option_name "title" of the taxonomy 34).
				],
				'post_tag' => []					// this is an example, post_tag is like a custom taxonomy
			],
			'shortcodes' => [						// array of shortcodes contains media id (es: [image image_id="12"])
				'image' => 'image_id'
			],
			'relations' => ['blog']					// array of post relations ids
			'sections' => ['post_sections']			// array of post sections ids (NB: specic attachments in attachments array)
		]
	]
]);
```
