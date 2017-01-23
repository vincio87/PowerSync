/**
 * PowerSync
 *
 * PowerSync is an awesome tool that sync database tables between WordPress installations.
 *
 * @author     Graziano Vincini <graziano.vincini@caffeina.com>
 * @author     Caffeina srl <dev@caffeina.com>
 * @version    Release: 1.0
 */

class PowerSync {

	public static function init(array $args = []) {

		define('SYNC',true); // SYSTEM SECURE FLAG

		$options = array_merge([
			'paths' => [
				'attachment_remote' => '',
				'wp_dir' => ''
			],
			'connections' => [
				'fdb_prms' => [
					'host' => false,
					'name' => false,
					'user' => false,
					'pass' => false
				],
				'tdb_prms' => [
					'host' => false,
					'name' => false,
					'user' => false,
					'pass' => false
				]
			],
			'post_types' =>  []

		],$args);

		// Basic Parameters Check
		// ----------------------

		if(empty($options['paths']['attachment_remote'])){
			self::text('Attenzione, non è stato impostato il parametro paths->attachment_remote necessario per importare i media. Il parametro corrisponde alla url assoluta della directory contenente i media da importare.','error');
			exit;
		}
		if(empty($options['paths']['wp_dir'])){
			self::text('Attenzione, non è stato impostato il parametro paths->wp_dir necessario per inizializzare wordpress. Il parametro corrisponde alla url relativa al file import della directory contenente wordpress.','error');
			exit;
		}
		if(empty($options['connections']['fdb_prms']) || empty($options['connections']['tdb_prms'])){
			self::text('Attenzione, assicurati di aver impostato i parametri di connessione ai database.','error');
			exit;
		}


		define('ATTACHMENT_BASE_PATH',$options['paths']['attachment_remote']);
		define('FDB_NAME',$options['connections']['fdb_prms']['name']); // From
		define('TDB_NAME',$options['connections']['tdb_prms']['name']); // To

		// Configuration Database | FROM
		// -----------------------------

		$fdb_prms = (object)$options['connections']['fdb_prms'];

		// Configuration Database | TO
		// -----------------------------

		$tdb_prms = (object)$options['connections']['tdb_prms'];


		// The *Magic* Begins
		// ----------------

		require $options['paths']['wp_dir'].'wp-load.php';
		global $wpdb;

		$fdb = new wpdb($fdb_prms->user,$fdb_prms->pass,$fdb_prms->name,$fdb_prms->host);


		if(empty($options['post_types'])){

			// Empty post types configuration, set default
			// -------------------------------------------

			self::label('INIT DEFAULT POST TYPES','INFO');
			self::text('Non è stato impostato alcun post type specifico da importare, determino automaticamente i post types da importare.');

			$post_types = [];

			foreach (get_post_types(['_builtin' => false]) as $k => $p) {
				$post_types[$k] = [
					'import' => true,
					'rewrite' => false,
					'date_priority' => true,
					'taxonomies_update' => false,
					'attachments' => ['_thumbnail_id'],
					'taxonomies' => ['category','post_tag'],
					'shortcodes' => [],
					'relations' => [],
					'sections' => []
				];
			}

		}else{

			// Configuration Post types to Import
			// ----------------------------------
			$post_types = $options['post_types'];

		}

		foreach ($post_types as $p_type => $p) {

			// Check Import Block
			// ------------------

			if(!$p['import']) continue;

			// Get all post types to import from wp_posts
			// ------------------------------------------

			$all_from = $fdb->get_results("SELECT * from wp_posts WHERE post_type = '{$p_type}' AND post_status = 'publish' ");

			if(!count($all_from)) continue;

			foreach ($all_from as $s_p) {

				self::text('------------------------------------------------------------');
				self::label($s_p->post_type.' | '.$s_p->post_title,'FEATURED');
				self::text(' - Inizio analisi post '.$s_p->ID);
				self::text('------------------------------------------------------------');

				$post_id_from = $s_p->ID;

				// Delete old post
				// ---------------

				$old_post = get_posts([
					'name' => $s_p->post_name,
					'post_type' => 'any',
					'posts_per_page' => 1,
					'post_status' => ['publish', 'pending', 'draft', 'future', 'private', 'inherit', 'trash']
				]);

				if(count($old_post)) {

					$old_post = current($old_post);

					// Post Founded in database
					// ------------------------

					self::label('FOUND SIMILAR POST '.$old_post->ID,'INFO');
					self::text('Trovato un post corrispondente al nuovo da importare');

					if(!$p['rewrite']){

						// Rewrite block. Stop operation and go to next post
						// -------------------------------------------------

						self::label('REWRITE BLOCK','WARNING');
						self::text('Non aggiorno il post, salto al post successivo');
						continue;
					}

					if($p['date_priority']){

						// Check Date Priority
						// -------------------

						$from_date = new DateTime($s_p->post_modified);
						$to_date = new DateTime($old_post->post_modified);

						if($to_date > $from_date){
							self::label('DATE PRIORITY BLOCK','WARNING');
							self::text('Il post già presente risulta più aggiornato del post da importare, salto al post successivo.');
							continue;
						}

					}

					if(wp_delete_post($old_post->ID)) {

						// Delete post. Go!
						// ----------------

						self::label('DELETE POST '.$old_post->ID,'SUCCESS');
						self::text('Post cancellato correttamente.');
					}
				}

				// Insert new post
				// ---------------

				$s_p->ID = 0;
				$post_id_to = wp_insert_post($s_p);

				if(!$post_id_to){
					self::label('INSERT POST '.$post_id_from,'FAILURE');
					self::text('Si è verificato un problema durante l\'importazione del post, salto al post successivo','error');
					continue;
				}

				self::label('INSERT POST '.$post_id_from.'. NEW POST ID '.$post_id_to,'SUCCESS');
				self::text('Post importato correttamente');

				// Get all post meta
				// -----------------

				$all_m_from = $fdb->get_results("SELECT * from wp_postmeta WHERE post_id = '{$post_id_from}'");
				$post_complete_to = get_post($post_id_to);

				// Chek shortcodes Media
				// ---------------------

				self::detect_shortcodes($post_complete_to,$p['shortcodes']);

				self::label('INSERT METADATA','INFO');
				self::text('Inserimento meta');

				foreach ($all_m_from as $m_from) {

					// Check metadata nature
					// ---------------------

					if(in_array($m_from->meta_key,$p['attachments'])){

						// Check case featured image
						// -------------------------

						$to_attach = $m_from->meta_key=='_thumbnail_id'?true:false;

						// Elaborate data attachment: 2 case -> single value id numeric or serialized data
						// -------------------------------------------------------------------------------

						$media_list = [];
						if(is_numeric($m_from->meta_value)){
							$media_list[] = $fdb->get_var("SELECT `meta_value` from wp_postmeta WHERE (meta_key = '_wp_attached_file' AND post_id = '{$m_from->meta_value}')");
						}else{

							// Check Multiple values (serialized attachments or string)
							// -------------------------------------------------------

							if (self::is_serial($m_from->meta_value)){
								foreach(unserialize($m_from->meta_value) as $uns){

								    if(empty($uns)) continue;

									if(is_numeric($uns)){
										$media_list[] = $fdb->get_var("SELECT `meta_value` from wp_postmeta WHERE (meta_key = '_wp_attached_file' AND post_id = '{$uns}')");
									}else{
										$media_list[] = end(explode('/', $uns));
									}
								}

							}else{
								$media_list[] = end(explode('/', $m_from->meta_value));
							}
						}

						// Insert attachmentS if not exists
						// --------------------------------

						foreach ($media_list as $a_name) {
							$a = $wpdb->get_results("SELECT * from wp_postmeta WHERE meta_value = '{$a_name}'");
							$a = is_array($a)?current($a):$a;
							$a_id = $a?$a->meta_id:0;

							// Attach if is featured image (_thumbnail_id), else add media and add post meta
							// -----------------------------------------------------------------------------

							if($to_attach){
								if(!$a_id){

									// Import attachment and attach it to post
									// ---------------------------------------

									$a_id = self::import_attachment($a_name,$post_id_to);

									if(!$a_id) {
										self::text('Errore durante l\'importazione dell\'attachment','error');
									}
								}else{

									// Attach attachment to post
									// -------------------------

									if(!set_post_thumbnail( $post_id_to, $a->post_id )){
										self::text('Si è verificato un problema durante l\'aggiunta dell\'attachment','error');
									}
								}
							}else{
								if(!$a_id){

									// Import attachment if not exist
									// ------------------------------

									$a_id = self::import_attachment($a_name);

									if(!$a_id) {
										self::text('Errore durante l\'importazione dell\'attachment','error');
									}
								}
							}

						}

						if(!$to_attach && !empty($media_list)){
							if(!add_post_meta($post_id_to,$m_from->meta_key,$m_from->meta_value)){
								self::text('Errore durante l\'inserimento del meta "'.$m_from->meta_key,'error');
							}
						}

					}else{

						// Insert classic meta, first check special case
						// ---------------------------------------------

						if(in_array($m_from->meta_key,$p['relations'])){

							$slug = $fdb->get_var("SELECT `post_name` from wp_posts WHERE ID = '{$m_from->meta_value}'");

							$p_a = get_posts([
								'name' => $slug,
								'post_type' => 'any',
								'posts_per_page' => 1,
								'post_status' => ['publish', 'pending', 'draft', 'future', 'private', 'inherit', 'trash']
							]);

							if(count($p_a)) {

								// Update relationship
								// -------------------

								$p_a = current($p_a);
								if(!add_post_meta($post_id_to,$m_from->meta_key,$p_a->ID)){
									self::text('Errore durante l\'inserimento del meta "'.$m_from->meta_key,'error');
								}
							}else{
								self::text('Attenzione, il post corrispondente alla relazione (meta id '.$m_from->meta_key.') non risulta inserito. La relazione non è stata inserita.','error');
							}

						}else{

							// Check Sections Attachments and import
							// -------------------------------------

							if(!empty($p['sections']) && in_array($m_from->meta_key,$p['sections'])){
								foreach (json_decode($m_from->meta_value) as $section_id => $section_values) {
									foreach ($section_values->fields as $section_field => $section_value) {
										if(in_array( $section_field,$p['attachments'])){
											$a_name = end(explode('/', $section_value));
											$a = $wpdb->get_results("SELECT * from wp_postmeta WHERE meta_value = '{$a_name}'");
											$a = is_array($a)?current($a):$a;
											$a_id = $a?$a->meta_id:0;

											if(!$a_id){

												// Import section attachment if not exist
												// --------------------------------------

												$a_id = self::import_attachment($a_name);

												if(!$a_id) {
													self::text('Errore durante l\'importazione dell\'attachment','error');
												}
											}
										}
									}
								}
							}

							if(!add_post_meta($post_id_to,$m_from->meta_key,$m_from->meta_value)){
								self::text('Errore durante l\'inserimento del meta "'.$m_from->meta_key,'error');
							}
						}
					}
				}

				// Check Taxonomies
				// ----------------

				if(empty($p['taxonomies'])) continue;

				self::label('INSERT TAXONOMIES','INFO');
				self::text('Inserimento tassonomie');

				foreach ($p['taxonomies'] as $id_tax => $tax) {

					$tax_meta = [];
					$tax_attachments = [];

					if($id_tax && is_array($tax)){

						// Check Taxonomy Options
						// ----------------------

						$tax_meta = !empty($tax['meta'])?$tax['meta']:[];
						$tax_attachments = !empty($tax['attachments'])?$tax['attachments']:[];

					}else{

						$id_tax = $tax;

					}

					$wpdb->select(FDB_NAME);
					$terms = wp_get_post_terms($post_id_from,$id_tax);
					$wpdb->select(TDB_NAME);

					if(empty($terms)) continue;

					foreach ($terms as $term) {

						$is_new_term = false;

						// Check Term. Insert if not exist
						// -------------------------------

						$t = $wpdb->get_results("SELECT * from wp_terms AS a INNER JOIN wp_term_taxonomy as b ON a.term_id = b.term_id WHERE a.slug = '{$term->slug}' AND b.taxonomy = '{$term->taxonomy}'");
						$t = is_array($t)?current($t):$t;
						$t_id = $t?$t->term_id:0;

						if(!$t_id){

							$new_term = wp_insert_term($term->name,$term->taxonomy,[
								'description' => $term->description,
								'parent' => $term->parent,
								'slug' => $term->slug
							]);

							$t_id = $new_term['term_id'];
							self::text('Aggiunta la nuova tassonomia '.$term->name);
							$is_new_term = true;

						}

						$term_id = $term->taxonomy == 'post_tag' ? $term->slug : $t_id;
						$label_tax = is_numeric($term_id)?'tassonomia':'tag';

						// Check Taxonomy "Meta" (options)
						// -------------------------------

						if(($p['taxonomies_update'] && !empty($tax_meta)) || $is_new_term) {

							// Insert / Update standard meta
							// ---------------------------

							foreach ($tax_meta as $t_m) {
								$t_m = str_replace('*',$t_m,$tax['rewrite_role']);
								$option_name_from = str_replace('%id%',$term->term_id,$t_m);
								$option_name_to = str_replace('%id%',$term_id,$t_m);

								$from_meta_value = $fdb->get_var("SELECT option_value from wp_options WHERE option_name = '{$option_name_from}'");

								if(!$from_meta_value) continue;

								update_option( $option_name_to, $from_meta_value );
							}

							// Insert / Update taxonomy attachment
							// ---------------------------------

							foreach ($tax_attachments as $t_m) {
								$t_m = str_replace('*',$t_m,$tax['rewrite_role']);
								$option_name_from = str_replace('%id%',$term->term_id,$t_m);
								$option_name_to = str_replace('%id%',$term_id,$t_m);

								$from_meta_value = $fdb->get_var("SELECT option_value from wp_options WHERE option_name = '{$option_name_from}'");

								if(!$from_meta_value) continue;

								update_option( $option_name_to, $from_meta_value );

								$a_name = end(explode('/', $from_meta_value));
								$a = $wpdb->get_results("SELECT * from wp_postmeta WHERE meta_value = '{$a_name}'");
								$a = is_array($a)?current($a):$a;
								$a_id = $a?$a->meta_id:0;

								if(!$a_id){

									// Import section attachment if not exist
									// --------------------------------------

									$a_id = self::import_attachment($a_name);

									if(!$a_id) {
										self::text('Errore durante l\'importazione dell\'attachment','error');
									}
								}
							}

						}

						if(wp_set_post_terms( $post_id_to, $term_id, $term->taxonomy, true )) {
							self::text('Associazione '.$label_tax.' '.$term_id.' ('.$term->name.') al post '.$post_id_to);
						}
					}
				}
			}
		}
	}

	public static function text($text, $status=null){
		if($status == 'error'){
			echo "\e[31m".$text."\e[0m ".PHP_EOL;
		}else{
			echo $text.PHP_EOL;
		}

	}

	public static function label($text, $status) {
	 	$out = "";
		switch($status) {
			case "SUCCESS":
				$out = "[0;32m";
				break;
			case "FAILURE":
				$out = "[31m";
				break;
			case "WARNING":
				$out = "[33m";
				break;
			case "INFO":
				$out = "[34m";
				break;
			case "FEATURED":
				$out = "[0;35m";
				break;
			default: throw new Exception("Invalid status: " . $status);
		}

		echo "\e".$out.$text."\e[0m ";

	}

	public static function detect_shortcodes($post,$shortcodes_list) {

		if(empty($shortcodes_list)) return false;

		self::label('DETECT SHORTCODES','INFO');
		self::text('Analisi content e check shortcodes');

		$content = $post->post_content;

		global $wpdb;

	    $pattern = get_shortcode_regex();
	    $shortcodes = [];

	    preg_match_all('/'.$pattern.'/uis', $content, $matches);

	    $i=0;
	    while ( isset( $matches[0][$i] ) ) {
	       $shortcodes[] = self::attribute_map($matches[0][$i]);
	       $i++;
	    }

	    foreach ($shortcodes as $k_shortcode => $shortcode) {

	        foreach ($shortcode as $k => $s) {

	        	if(in_array($k,array_keys($shortcodes_list))){
	  				$wpdb->select(FDB_NAME);
	                $attachment = wp_get_attachment_metadata(intval($s[$shortcodes_list[$k]]));
	                $wpdb->select(TDB_NAME);
	                if($attachment) $media[] = [
	                    'id'    => intval($s['image_id']),
	                    'name' => $attachment['file'],
	                    'shortcode' => [
	                    	'name' => $k,
	                    	'attr' => $shortcodes_list[$k]
	                    ]
	                ];
	        	}
	        }
	    }

	    self::text('Trovati '.count($media).' media shorcodes associati');

	    if(empty($media)) return true;

	    foreach ($media as $m) {
	    	$a = $wpdb->get_results("SELECT * from wp_postmeta WHERE meta_value = '{$m["name"]}'");
			$a = is_array($a)?current($a):$a;

			if(!$a) {
				$a_id = self::import_attachment($m["name"]);
				if(!$a_id) {
					self::text('Si è vericato un problema durante l\'importazione dello shortcode '.$m["name"],'error');
				}else{
					self::text('Media shortcode importato correttamente: '.$m["name"]);
				}
			}else{
				$a_id = $a->post_id;
				self::text('Media shortcode '.$m["name"].' già esistente. Non è necessaria l\'importazione');
			}
			$content = str_replace($m["shortcode"]["attr"].'="'.$m["id"].'"',$m["shortcode"]["attr"].'="'.$a_id.'"',$content);

			if(wp_update_post(['ID' => $post->ID, 'post_content' => $content])) self::text('Aggiornato lo shortcode '.$m["name"].' nel content del post '.$post->ID);
	    }

	    return true;
	}

	public static function is_serial($string) {
	    return (@unserialize($string) !== false);
	}

	public static function attribute_map($str, $att = null) {
	    $res = [];
	    $reg = get_shortcode_regex();
	    preg_match_all('~'.$reg.'~',$str, $matches);
	    foreach($matches[2] as $key => $name) {
	        $parsed = shortcode_parse_atts($matches[3][$key]);
	        $parsed = is_array($parsed) ? $parsed : [];

	        if(array_key_exists($name, $res)) {
	            $arr = [];
	            if(is_array($res[$name])) {
	                $arr = $res[$name];
	            } else {
	                $arr[] = $res[$name];
	            }

	            $arr[] = array_key_exists($att, $parsed) ? $parsed[$att] : $parsed;
	            $res[$name] = $arr;

	        } else {
	            $res[$name] = array_key_exists($att, $parsed) ? $parsed[$att] : $parsed;
	        }
	    }

	    return $res;
	}


	public static function import_attachment( $filename, $post_id=null ) {

		self::label('IMPORT ATTACHMENT '.$filename,'INFO');
		self::text('Importazione attachment to media library');

		if($post_id){
			$post = get_post($post_id);
			if(empty($post)) return false;
		}


		$upload_dir = wp_upload_dir();
		$file = $upload_dir['path'] . '/' . $filename;

		$attachment = file_get_contents(ATTACHMENT_BASE_PATH.$filename);

		// Create the image  file on the server
		file_put_contents( $file, $attachment );

		// Check image file type
		$wp_filetype = wp_check_filetype( $filename, null );

		// Set attachment data
		$attachment = [
		    'post_mime_type' => $wp_filetype['type'],
		    'post_title'     => sanitize_file_name( $filename ),
		    'post_content'   => '',
		    'post_status'    => 'inherit',
		    'guid' => '/'.$filename
		];

		// Create the attachment
		$attach_id = wp_insert_attachment( $attachment, $file, $post_id );

		// Include image.php
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		// Define attachment metadata
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file );

		// Assign metadata to attachment
		wp_update_attachment_metadata( $attach_id, $attach_data );

		// And finally assign featured image to post
		if($post_id) set_post_thumbnail( $post_id, $attach_id );

		return $attach_id;

	}

}
