<?php
/**
 * TermMenus
 *
 * @todo allow renaming/editing of menu items
 * @todo style everything so it looks good
 * @todo show description with name on post publish checkboxes
 * @todo PHPDoc
 * @todo ACL, CSRF, etc.
 */
class TermMenus extends Plugin
{
	public function  __get($name)
	{
		switch ( $name ) {
			case 'vocabulary':
				if ( !isset($this->_vocabulary) ) {
					$this->_vocabulary = Vocabulary::get(self::$vocabulary);
				}
				return $this->_vocabulary;
		}
	}

	/**
	 * Create an admin token for editing menus
	 **/
	public function action_plugin_activation($file)
	{
		// create default access token
		ACL::create_token( 'manage_menus', _t( 'Manage menus', 'termmenus' ), 'Administration', false );
		$group = UserGroup::get_by_name( 'admin' );
		$group->grant( 'manage_menus' );

		// register menu types
		Vocabulary::add_object_type( 'menu_link' );
		Vocabulary::add_object_type( 'menu_spacer' );
	}

	/**
	 * Register the templates - one for the admin page, the other for the block.
	 **/
	public function action_init()
	{
		$this->add_template( 'menus_admin', dirname( __FILE__ ) . '/menus_admin.php' );
		$this->add_template( 'menu_iframe', dirname( __FILE__ ) . '/menu_iframe.php' );
		$this->add_template( 'block.menu', dirname( __FILE__ ) . '/block.menu.php' );

		// formcontrol for tokens
		$this->add_template( 'text_tokens', dirname( __FILE__ ) . '/formcontrol_tokens.php' );
		$this->add_template( 'transparent_text', dirname( __FILE__ ) . '/admincontrol_text_transparent.php' );

		// i18n
		$this->load_text_domain( 'termmenus' );
	}

	/**
	 * Remove the admin token
	 **/
	public function action_plugin_deactivation( $file )
	{
		// delete default access token
		ACL::destroy_token( 'manage_menus' );

		// delete menu vocabularies that were created
		$vocabs = DB::get_results( 'SELECT * FROM {vocabularies} WHERE name LIKE "menu_%"', array(), 'Vocabulary' );
		foreach( $vocabs as $vocab ) {
			// This should only delete the ones that are menu vocabularies, unless others have been named 'menu_xxxxx'
			$vocab->delete();
		}

		// delete blocks that were created
		$blocks = DB::get_results( 'SELECT * FROM {blocks} WHERE type = "menu"', array(), 'Block') ;
		foreach( $blocks as $block ) {
			$block->delete();
		}
	}

	/**
	 * Add to the list of possible block types.
	 **/
	public function filter_block_list($block_list)
	{
		$block_list['menu'] = _t( 'Menu', 'termmenus' );
		return $block_list;
	}

	/**
	 * Produce the form to configure a menu
	 **/
	public function action_block_form_menu( $form, $block )
	{
		$form->append('select', 'menu_taxonomy', $block, _t( 'Menu Taxonomy', 'termmenus' ), $this->get_menus( true ) );
		$form->append('checkbox', 'div_wrap', $block, _t( 'Wrap each menu link in a div', 'termmenus' ) );
		$form->append('text', 'list_class', $block, _t( 'Custom class for the tree ordered list element', 'termmenus' ) );
	}

	/**
	 * Populate the block with some content
	 **/
	public function action_block_content_menu( $block, $theme ) {
		$vocab = Vocabulary::get_by_id($block->menu_taxonomy);
		$block->vocabulary = $vocab;
		if($block->div_wrap) {
			$wrapper = '<div>%s</div>';
		}
		else {
			$wrapper = '%s';
		}

		// preprocess some things
		$tree = $vocab->get_tree();

		$block->content = Format::term_tree(
			$tree, //$vocab->get_tree(),
			$vocab->name,
			array(
				//'linkcallback' => array($this, 'render_menu_link'),
				'itemcallback' => array($this, 'render_menu_item'),
				'linkwrapper' => $wrapper,
				'treeattr' => array(
					'class' => $block->list_class,
				),
				'theme' => $theme,
			)
		);
	}

	/**
	 * Add menus to the publish form
	 **/
	public function action_form_publish ( $form, $post )
	{
		$menus = $this->get_menus();

		$menulist = array();
		foreach($menus as $menu) {
			$menulist[$menu->id] = $menu->name;
		}

		$settings = $form->publish_controls->append( 'fieldset', 'menu_set', _t( 'Menus', 'termmenus' ) );
		$settings->append( 'checkboxes', 'menus', 'null:null', _t( 'Menus', 'termmenus' ), $menulist );

		// If this is an existing post, see if it has categories already
		if ( 0 != $post->id ) {
			// Get the terms associated to this post
			$object_terms = Vocabulary::get_all_object_terms( 'post', $post->id );
			$menu_ids = array_keys( $menulist );
			$value = array();
			// if the term is in a menu vocab, enable that checkbox
			foreach( $object_terms as $term ) {
				if( in_array( $term->vocabulary_id, $menu_ids ) ) {
					$value[] = $term->vocabulary_id;
				}
			}

			$form->menus->value = $value;
		}
	}

	/**
	 * Process menus when the publish form is received
	 *
	 **/
	public function action_publish_post( $post, $form )
	{
		// might not hurt to turn this into a function to be more DRY
		$term_title = $post->title;
		$selected_menus = $form->menus->value;
		foreach( $this->get_menus() as $menu ) {
			if(in_array( $menu->id, $selected_menus ) ) {
				$terms = $menu->get_object_terms( 'post', $post->id );
				if( count( $terms ) == 0 ) {
					$term = new Term(array(
						'term_display' => $post->title,
						'term' => $post->slug,
					));
					$term->info->menu = $menu->id;
					$menu->add_term( $term );
					$menu->set_object_terms( 'post',
						$post->id,
						array( $term->term ) );
				}
			}
		}
	}

	/**
	 * Add creation and management links to the main menu
	 *
	 **/
	public function filter_adminhandler_post_loadplugins_main_menu( $menu ) {
		$menus_array = array( 'create_menu' => array(
			'title' => "Create a new Menu",
			'text' => "New Menu",
			'hotkey' => 'N',
			'url' => URL::get( 'admin', array( 'page' => 'menus', 'action' => 'create' ) ),
			'class' => 'over-spacer',
			'access' => array( 'manage_menus' => true ),
		));

		$items = 0;
		foreach ( $this->get_menus() as $item  ) {
			$menus_array[ ++$items ] = array(
				'title' => "{$item->name}: {$item->description}",
				'text' => $item->name,
				'hotkey' => $items,
				'url' => URL::get( 'admin', array( 'page' => 'menus', 'action' => 'edit', 'menu' => $item->id )),
				'access' => array( 'manage_menus' => true ),
			);
		}
		if ( count( $menus_array ) > 1 ) {
			$menus_array[1]['class'] = 'under-spacer';
		}

		// add to main menu
		$item_menu = array( 'menus' =>
			array(
				'url' => URL::get( 'admin', 'page=menus' ),
				'title' => _t( 'Menus', 'termmenus' ),
				'text' => _t( 'Menus', 'termmenus' ),
				'hotkey' => 'E',
				'selected' => false,
				'submenu' => $menus_array,
			)
		);

		$slice_point = array_search( 'themes', array_keys( $menu ) ); // Element will be inserted before "themes"
		$pre_slice = array_slice( $menu, 0, $slice_point);
		$post_slice = array_slice( $menu, $slice_point);

		$menu = array_merge( $pre_slice, $item_menu, $post_slice );

		return $menu;
	}

	/**
	 * Handle GET and POST requests
	 *
	 **/
	public function alias()
	{
		return array(
			'action_admin_theme_get_menus' => 'action_admin_theme_post_menus',
			'action_admin_theme_get_menu_iframe' => 'action_admin_theme_post_menu_iframe',
		);
	}

	/**
	 * Restrict access to the admin page
	 *
	 **/
	public function filter_admin_access_tokens( array $require_any, $page )
	{
		switch ( $page ) {
			case 'menu_iframe':
			case 'menus':
				$require_any = array( 'manage_menus' => true );
				break;
		}
		return $require_any;
	}

	/**
	 * Convenience function for obtaining menu type data and caching it so that it's not called repeatedly
	 *
	 * The return value should include specific paramters, which are used to feed the menu creation routines.
	 * The array should return a structure like the following:
	 * <code>
	 * $menus = array(
	 * 	'typename' => array(
	 * 		'form' => function(FormUI $form, Term|null $term){ },
	 *
	 * 	)
	 * );
	 * </code>
	 * @return array
	 */
	public function get_menu_type_data()
	{
		static $menu_type_data = null;
		if(empty($menu_type_data)) {
			$menu_type_data = Plugins::filter('menu_type_data', array());
		}
		return $menu_type_data;
	}

	/**
	 * Implementation of menu_type_data filter, created by this plugin
	 * @param array $menu_type_data Existing menu type data
	 * @return array Updated menu type data
	 */
	public function filter_menu_type_data($menu_type_data)
	{
		$menu_type_data['menu_link'] = array(
			'label' => _t('Link', 'termmenus'),
			'form' => function($form, $term) {
				$link_name = new FormControlText( 'link_name', 'null:null', _t( 'Link Title', 'termmenus' ) );
				$link_name->add_validator( 'validate_required', _t( 'A name is required.', 'termmenus' ) );
				$link_url = new FormControlText( 'link_url', 'null:null', _t( 'Link URL', 'termmenus' ) );
				$link_url->add_validator( 'validate_required' )
					->add_validator( 'validate_url', _t( 'You must supply a valid URL.', 'termmenus' ) );
				if ( $term ) {
					$link_name->value = $term->term_display;
					$link_url->value = $term->info->url;
					$form->append( 'hidden', 'term' )->value = $term->id;
				}
				$form->append( $link_name );
				$form->append( $link_url );
			},
			'save' => function($menu, $form) {
				if ( !$form->term->value ) {
					$term = new Term(array(
						'term_display' => $form->link_name->value,
						'term' => Utils::slugify($form->link_name->value),
					));
					$term->info->type = "link";
					$term->info->url = $form->link_url->value;
					$term->info->menu = $menu->id;
					$menu->add_term($term);
					$term->associate('menu_link', 0);

					Session::notice(_t('Link added.', 'termmenus'));
				} else 	{
					$term = Term::get( intval( $form->term->value ) );
					$updated = false;
					if ( $term->info->url !== $form->link_url->value ) {
						$term->info->url = $form->link_url->value;
						$updated = true;
					}
					if ( $form->link_name->value !== $term->term_display ) {
						$term->term_display = $form->link_name->value;
						$term->term = Utils::slugify( $form->link_name->value );
						$updated = true;
					}

					$term->info->url = $form->link_url->value;

					if ( $updated ) {
						$term->update();
						Session::notice( _t( 'Link updated.', 'termmenus' ) );
					}
				}
			},
			'render' => function($term, $object_id, $config) {
				$result = array(
					'link' => $term->info->url,
				);
				return $result;
			}
		);
		$menu_type_data['menu_spacer'] = array(
			'label' => _t('Spacer', 'termmenus'),
			'form' => function($form, $term) {
				$spacer = new FormControlText( 'spacer_text', 'null:null', _t( 'Item text', 'termmenus' ), 'optionscontrol_text' );
				$spacer->helptext = _t( 'Leave blank for blank space', 'termmenus' );
				if ( $term ) {
					$spacer->value = $term->term_display;
					$form->append( 'hidden', 'term' )->value = $term->id;
				}

				$form->append( $spacer );
			},
			'save' => function($menu, $form) {
				if ( !$form->term->value ) {
					$term = new Term(array(
						'term_display' => ($form->spacer_text->value !== '' ? $form->spacer_text->value : '&nbsp;'), // totally blank values collapse the term display in the formcontrol
						'term' => Utils::slugify(($form->spacer_text->value !== '' ? $form->spacer_text->value : 'menu_spacer')),
					));
					$term->info->type = "spacer";
					$term->info->menu = $menu->id;
					$menu->add_term($term);
					$term->associate('menu_spacer', 0);

					Session::notice(_t('Spacer added.', 'termmenus'));
				} else {
					$term = Term::get( intval( $form->term->value ) );
					if ($form->spacer_text->value !== $term->term_display ) {
						$term->term_display = $form->spacer_text->value;
						$term->update();
						Session::notice( _t( 'Spacer updated.', 'termmenus' ) );
					}
				}
			}
		);
		$menu_type_data['post'] = array(
			'label' => _t('Links to Posts', 'termmenus'),
			'form' => function($form, $term) {
				$post_ids = $form->append( 'text', 'post_ids', 'null:null', _t( 'Posts', 'termmenus' ) );
				$post_ids->template = 'text_tokens';
				$post_ids->ready_function = "$('#{$post_ids->field}').tokenInput( habari.url.ajaxPostTokens )";
			},
			'save' => function($menu, $form) {
				$post_ids = explode( ',', $form->post_ids->value );
				foreach( $post_ids as $post_id ) {
					$post = Post::get( array( 'id' => $post_id ) );
					$term_title = $post->title;

					$terms = $menu->get_object_terms( 'post', $post->id );
					if( count( $terms ) == 0 ) {
						$term = new Term( array( 'term_display' => $post->title, 'term' => $post->slug ) );
						$term->info->menu = $menu->id;
						$menu->add_term( $term );
						$menu->set_object_terms( 'post', $post->id, array( $term->term ) );
					}
				}
				Session::notice(_t( 'Link(s) added.', 'termmenus' ));
			},
			'render' => function($term, $object_id, $config) {
				$result = array();
				if($post = Post::get($object_id)) {
					$result['link'] = $post->permalink;
				}
				return $result;
			}
		);
		return $menu_type_data;
	}

	/**
	 * @return array The data array
	 */
	public function get_menu_type_ids()
	{
		static $menu_item_ids = null;
		if(empty($menu_item_ids)) {
			$menu_item_types = $this->get_menu_type_data();
			$menu_item_types = Utils::array_map_field($menu_item_types, 'type_id');
			$menu_item_types = array_flip($menu_item_ids);
		}
		return $menu_item_ids;
	}

	/**
	 * Minimal modal forms
	 *
	 **/
	public function action_admin_theme_get_menu_iframe( AdminHandler $handler, Theme $theme )
	{
		$action = isset($_GET[ 'action' ]) ? $_GET[ 'action' ] : 'create';
		$term = null;
		if ( isset( $handler->handler_vars[ 'term' ] ) ) {
			$term = Term::get( intval( $handler->handler_vars[ 'term' ] ) );
			$object_types = $term->object_types();
			$action = $object_types[0]->type; // the 'menu_whatever' we seek should be the only element in the array.
			$form_action = URL::get( 'admin', array( 'page' => 'menu_iframe', 'menu' => $handler->handler_vars[ 'menu' ], 'term' => $handler->handler_vars[ 'term' ], 'action' => "$action" ) );
		} else {
			$form_action = URL::get( 'admin', array( 'page' => 'menu_iframe', 'menu' => $handler->handler_vars[ 'menu' ], 'action' => "$action" ) );
		}
		$form = new FormUI( 'menu_item_edit', $action );
		$form->class[] = 'tm_db_action';
		$form->set_option( 'form_action', $form_action );
		$form->append( 'hidden', 'menu' )->value = $handler->handler_vars[ 'menu' ];
		$form->on_success( array( $this, 'term_form_save' ) );

		$menu_types = $this->get_menu_type_data();

		if(isset($menu_types[$action])) {
			$menu_types[$action]['form']($form, $term);
			$form->append( 'hidden', 'menu_type' )->value = $action;
			$form->append( 'submit', 'submit', _t( '%1$s %2$s', array( $term ? _t( 'Update', 'termmenus' ) : _t( 'Add', 'termmenus' ), $menu_types[$action]['label'] ), 'termmenus' ) );
		}

		$form->properties['onsubmit'] = "return habari.menu_admin.submit_menu_item_edit()";

		$theme->page_content = $form->get();

		if(isset($_GET['result'])) {
			switch($_GET['result']) {
				case 'added':
					$treeurl = URL::get( 'admin', array('page' => 'menus', 'menu' => $handler->handler_vars[ 'menu' ], 'action' => 'edit') ) . ' #edit_menu>*';
					$msg = _t( 'Menu item added.', 'termmenus' ); // @todo: update this to reflect if more than one item has been added, or reword entirely.
					$theme->page_content .= <<< JAVSCRIPT_RESPONSE
<script type="text/javascript">
human_msg.display_msg('{$msg}');
$('#edit_menu').load('{$treeurl}', habari.menu_admin.init_form);
</script>
JAVSCRIPT_RESPONSE;
			}
		}
		$theme->display( 'menu_iframe' );
		exit;
	}

	/**
	 * Prepare and display admin page
	 *
	 **/
	public function action_admin_theme_get_menus( AdminHandler $handler, Theme $theme )
	{
		$theme->page_content = '';
		$action = isset($_GET[ 'action' ]) ? $_GET[ 'action' ] : 'create';
		switch( $action ) {
			case 'edit':
				$vocabulary = Vocabulary::get_by_id( intval( $handler->handler_vars[ 'menu' ] ) );
				if ( $vocabulary == false ) {
					$theme->page_content = _t( '<h2>Invalid Menu.</h2>', 'termmenus' );
					// that's it, we're done. Maybe we show the list of menus instead?
					break;
				}

				$form = new FormUI( 'edit_menu' );

				$form->append( new FormControlText( 'menuname', 'null:null', _t( 'Name', 'termmenus' ), 'transparent_text' ) )
					->add_validator( 'validate_required', _t( 'You must supply a valid menu name', 'termmenus' ) )
					->add_validator( array( $this, 'validate_newvocab' ) )
					->value = $vocabulary->name;
				$form->append( new FormControlHidden( 'oldname', 'null:null' ) )->value = $vocabulary->name;

				$form->append( new FormControlText( 'description', 'null:null', _t( 'Description', 'termmenus' ), 'transparent_text' ) )
					->value = $vocabulary->description;

				$edit_items_array = $this->get_menu_type_data();

				$edit_items = '';
				foreach( $edit_items_array as $action => $menu_type ) {
					$edit_items .= '<a class="modal_popup_form menu_button_dark" href="' . URL::get('admin', array(
						'page' => 'menu_iframe',
						'action' => $action,
						'menu' => $vocabulary->id,
					) ) . "\">" . _t('Add %s', array($menu_type['label']), 'termmenus') .  "</a>";
				}

				if ( !$vocabulary->is_empty() ) {
					$form->append( 'tree', 'tree', $vocabulary->get_tree(), _t( 'Menu', 'termmenus') );
					$form->tree->config = array( 'itemcallback' => array( $this, 'tree_item_callback' ) );
//						$form->tree->value = $vocabulary->get_root_terms();
					// append other needed controls, if there are any.

					$form->append( 'static', 'buttons', _t( "<div id='menu_item_button_container'>$edit_items</div>", 'termmenus' ) );
					$form->append( 'submit', 'save', _t( 'Apply Changes', 'termmenus' ) );
				}
				else {
					$form->append( 'static', 'buttons', _t( "<div id='menu_item_button_container'>$edit_items</div>", 'termmenus' ) );
				}
				$delete_link = URL::get( 'admin', array( 'page' => 'menus', 'action' => 'delete_menu', 'menu' => $handler->handler_vars[ 'menu' ] ) );
				$form->append( 'static', 'deletebutton', _t( "<a class='a_button' href='$delete_link'>Delete Menu</a>", 'termmenus' ) );
				$form->append( new FormControlHidden( 'menu', 'null:null' ) )->value = $handler->handler_vars[ 'menu' ];
				$form->on_success( array( $this, 'rename_menu_form_save' ) );
				$theme->page_content .= $form->get();
				break;

			case 'create':
				$form = new FormUI('create_menu');
				$form->append( 'text', 'menuname', 'null:null', _t( 'Menu Name', 'termmenus' ), 'transparent_text' )
					->add_validator( 'validate_required', _t( 'You must supply a valid menu name', 'termmenus' ) )
					->add_validator( array($this, 'validate_newvocab' ) );
				$form->append( 'text', 'description', 'null:null', _t( 'Description', 'termmenus' ), 'transparent_text' );
				$form->append( 'submit', 'submit', _t( 'Create Menu', 'termmenus' ) );
				$form->on_success( array( $this, 'add_menu_form_save' ) );
				$theme->page_content = $form->get();

				break;

			case 'delete_menu':
				$menu_vocab = Vocabulary::get_by_id( intval( $handler->handler_vars[ 'menu' ] ) );
				$menu_vocab->delete();
				// log that it has been deleted?
				Session::notice( _t( 'Menu deleted.', 'termmenus' ) );
				// redirect to a blank menu creation form
				Utils::redirect( URL::get( 'admin', array( 'page' => 'menus', 'action' => 'create' ) ) );
				break;

			case 'delete_term':
				$term = Term::get( intval( $handler->handler_vars[ 'term' ] ) );
				$menu_vocab = $term->vocabulary_id;
				$term->delete();
				// log that it has been deleted?
				Session::notice( _t( 'Item deleted.', 'termmenus' ) );
				Utils::redirect( URL::get( 'admin', array( 'page' => 'menus', 'action' => 'edit', 'menu' => $menu_vocab ) ) );
				break;
			default:
				Utils::debug( $_GET, $action ); die();
		}

		$theme->display( 'menus_admin' );
		// End everything
		exit;
	}

	public function add_menu_form_save( $form )
	{
		$params = array(
			'name' => $form->menuname->value,
			'description' => $form->description->value,
			'features' => array(
				'term_menu', // a special feature that marks the vocabulary as a menu, but has no functional purpose
				'unique', // a special feature that applies a one-to-one relationship between term and object, enforced by the Vocabulary class
			),
		);
		$vocab = Vocabulary::create( $params );

		Utils::redirect( URL::get( 'admin', array( 'page' => 'menus', 'action' => 'edit', 'menu' => $vocab->id ) ) );
	}

	public function rename_menu_form_save( $form )
	{
		// The name of this should probably change, since it is the on_success for the whole menu edit, no longer just for renaming.
		// It only renames/modifies the description currently, as item adding/rearranging is done by the NestedSortable tree.

		// get the menu from the form, grab the values, modify the vocabulary.
		$menu_vocab = intval( $form->menu->value );
		// create a term for the link, store the URL
		$menu = Vocabulary::get_by_id( $menu_vocab );
		if( $menu->name != $form->menuname->value ) {
			$menu->name = $form->menuname->value; // could use Vocabulary::rename for this
		}
		$menu->description = $form->description->value; // no Vocabulary function for this
		$menu->update();

		$form->save();

		Session::notice( _t( 'Updated menu "%s".', array( $form->menuname->value ), 'termmenus' ) );
		Utils::redirect( URL::get( 'admin', array(
			'page' => 'menus',
			'action' => 'edit',
			'menu' => $menu->id,
		) ) );
	}

	public function term_form_save( $form )
	{
		$menu_vocab = intval( $form->menu->value );
		$menu = Vocabulary::get_by_id( $menu_vocab );
		$menu_type_data = $this->get_menu_type_data();

		if ( isset( $form->term ) ) {
			$term = Term::get( intval( (string) $form->term->value ) );
			// maybe we should check if term exists? Or put that in the conditional above?
			$object_types = $term->object_types();
			$type = $object_types[0]->type; // that's twice we've grabbed the $term->object_types()[0]. Maybe this is a job for a function?

			if(isset($menu_type_data[$type]['save'])) {
				$menu_type_data[$type]['save']($menu, $form);
			}

		}
		else { // if no term is set, create a new item.
			// create a term for the link, store the URL

			$type = $form->menu_type->value;
			if(isset($menu_type_data[$type]['save'])) {
				$menu_type_data[$type]['save']($menu, $form);
			}

			// if not for this redirect, this whole if/else could be simplified considerably.
			Utils::redirect(URL::get( 'admin', array(
				'page' => 'menu_iframe',
				'action' => $type,
				'menu' => $menu_vocab,
				'result' => 'added',
			) ) );
		}
	}

	public function validate_newvocab( $value, $control, $form )
	{
		if( isset( $form->oldname ) && ( $form->oldname->value ) && ( $value == $form->oldname->value ) ) {
			return array();
		}
		if(Vocabulary::get( $value ) instanceof Vocabulary) {
			return array( _t( 'Please choose a vocabulary name that does not already exist.', 'termmenus' ) );
		}
		return array();
	}

	public function get_menus($as_array = false)
	{
		$vocabularies = Vocabulary::get_all();
		$outarray = array();
		foreach ( $vocabularies as $index => $menu ) {
			if( !$menu->term_menu ) { // check for the term_menu feature we added.
				unset( $vocabularies[ $index ] );
			}
			else {
				if( $as_array ) {
					$outarray[ $menu->id ] = $menu->name;
				}
			}
		}
		if( $as_array ) {
			return $outarray;
		}
		else {
			return $vocabularies;
		}
	}

	/**
	 *
	 * Callback for Format::term_tree to use with $config['linkcallback']
	 *
	 * @param Term $term
	 * @param array $config
	 * @return array $config modified with the new wrapper div
	 **/
	public function tree_item_callback( Term $term, $config )
	{
		// coming into this, default $config['wrapper'] is "<div>%s</div>"

		// make the links
		$edit_link = URL::get( 'admin', array(
			'page' => 'menu_iframe',
			'action' => $term->info->type,
			'term' => $term->id,
			'menu' => $term->info->menu,
		) );
		$delete_link = URL::get( 'admin', array(
			'page' => 'menus',
			'action' => 'delete_term',
			'term' => $term->id,
			'menu' => $term->info->menu,
		) );

		// insert them into the wrapper
		// @TODO _t() this line or replace it altogether.
		$links = "<ul class='dropbutton'><li><a title='Edit this' class='modal_popup_form' href='$edit_link'>edit</a></li><li><a title='Delete this' href='$delete_link'>delete</a></li></ul>";
		$config[ 'wrapper' ] = "<div>%s $links</div>";

		return $config;
	}

	/**
	 * Callback function for block output of menu list item
	 **/
	public function render_menu_item( Term $term, $config )
	{
		$title = $term->term_display;

		$active = false;

		$menu_type_data = $this->get_menu_type_data();

		$spacer = false;
		$active = false;
		$link = null;
		if(!isset($term->object_id)) {
			$objects = $term->object_types();
			$term->type = reset($objects);
			$term->object_id = key($objects);
		}
		if(isset($menu_type_data[$term->type->type]['render'])) {
			$result = $menu_type_data[$term->type->type]['render']($term, $term->object_id, $config);
			$result = array_intersect_key(
				$result,
				array(
					'link' => 1,
					'title' => 1,
					'active' => 1,
					'spacer' => 1,
					'config' => 1,
				)
			);
			extract($result);
		}

		if( empty( $link ) ) {
			$config[ 'wrapper' ] = sprintf($config[ 'linkwrapper' ], $title);
		}
		else {
			$config[ 'wrapper' ] = sprintf( $config[ 'linkwrapper' ], "<a href=\"{$link}\">{$title}</a>" );
		}
		if( $active ) {
			$config[ 'itemattr' ][ 'class' ] = 'active';
		}
		else {
			$config[ 'itemattr' ][ 'class' ] = 'inactive';
		}
		if( $spacer ) {
			$config[ 'itemattr' ][ 'class' ] .= ' spacer';
		}
		return $config;
	}
	/**
	 * Add required Javascript and, for now, CSS.
	 */
	public function action_admin_header( $theme )
	{
		if ( $theme->page == 'menus' ) {
			// Ideally the plugin would reuse reusable portions of the existing admin CSS. Until then, let's only add the CSS needed on the menus page.
			Stack::add( 'admin_stylesheet', array( $this->get_url() . '/admin.css', 'screen' ), 'admin-css' );

			// Load the plugin and its css
			Stack::add( 'admin_header_javascript', Site::get_url( 'vendor' ) . "/jquery.tokeninput.js", 'jquery-tokeninput', 'jquery.ui' );
			Stack::add( 'admin_stylesheet', array( Site::get_url( 'admin_theme' ) . '/css/token-input.css', 'screen' ), 'admin_tokeninput' );

			// Add the callback URL.
			$url = "habari.url.ajaxPostTokens = '" . URL::get( 'ajax', array( 'context' => 'post_tokens' ) ) . "';";
			Stack::add( 'admin_header_javascript', $url, 'post_tokens_url', 'post_tokens' );

			// Add the menu administration javascript
			Stack::add( 'admin_header_javascript', $this->get_url('/menus_admin.js'), 'menus_admin', 'admin');
		}
	}

	/**
	 * Respond to Javascript callbacks
	 * The name of this method is action_ajax_ followed by what you passed to the context parameter above.
	 */
	public function action_ajax_post_tokens( $handler )
	{
		// Get the data that was sent
		$response = $handler->handler_vars[ 'q' ];
		// Wipe anything else that's in the buffer
		ob_end_clean();

		$new_response = Posts::get( array( "title_search" => $response ) );

		$final_response = array();
		foreach ( $new_response as $post ) {

			$final_response[] = array(
				'id' => $post->id,
				'name' => $post->title,
			);
		}
		// Send the response
		echo json_encode( $final_response );
	}
}
?>
