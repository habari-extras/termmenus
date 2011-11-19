<?php
/**
 * TermMenus
 *
 * @todo allow renaming/editing of menu items
 * @todo allow deleting of menus
 * @todo style everything so it looks good
 * @todo PHPDoc
 * @todo ACL, CSRF, etc.
 */
class TermMenus extends Plugin
{
	// define values to be stored as $object_id in Terms of type 'menu'
	private $item_types = array(
		'url' => 0,
		'spacer' => 1,	// a spacer is an item that goes nowhere.
	);

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

		// register a menu type
		Vocabulary::add_object_type( 'menu' );
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
		// obtain existing last submenu item
		$last_used = end( $menu[ 'create' ][ 'submenu' ]);
		// add a menu item at the bottom
		$menu[ 'create' ][ 'submenu' ][] = array(
			'title' => _t( 'Create a new Menu', 'termmenus' ),
			'url' => URL::get( 'admin', array( 'page' => 'menus', 'action' => 'create' ) ),
			'text' => _t( 'Menu', 'termmenus' ),
			'hotkey' => $last_used[ 'hotkey' ] + 1, // next available hotkey is last used + 1
		);
		$last_used = end( $menu[ 'manage' ][ 'submenu' ]);
		$menu[ 'manage' ][ 'submenu' ][] = array(
			'title' => _t( 'Manage Menus', 'termmenus' ),
			'url' => URL::get( 'admin', 'page=menus' ), // might as well make listing the existing menus the default
			'text' => _t( 'Menus', 'termmenus' ),
			'hotkey' => $last_used[ 'hotkey' ] + 1,
		);

		// add to main menu
		$item_menu = array( 'menus' =>
			array(
				'url' => URL::get( 'admin', 'page=menus' ),
				'title' => _t( 'Menus', 'termmenus' ),
				'text' => _t( 'Menus', 'termmenus' ),
				'hotkey' => 'E',
				'selected' => false
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
	 * Minimal modal forms
	 *
	 **/
	public function action_admin_theme_get_menu_iframe( AdminHandler $handler, Theme $theme )
	{
		$action = isset($_GET[ 'action' ]) ? $_GET[ 'action' ] : 'list';
		$form_action = URL::get( 'admin', array( 'page' => 'menu_iframe', 'menu' => $handler->handler_vars[ 'menu' ], 'action' => $action ) );
		switch( $action ) {
			case 'create_link':
				$form = new FormUI( 'create_link' );
				$form->class[] = 'tm_db_action';
				$form->append( 'text', 'link_name', 'null:null', _t( 'Link Title', 'termmenus' ) )
					->add_validator( 'validate_required', _t( 'A name is required.', 'termmenus' ) );
				$form->append( 'text', 'link_url', 'null:null', _t( 'Link URL', 'termmenus' ) )
					->add_validator( 'validate_required' )
					->add_validator( 'validate_url', _t( 'You must supply a valid URL.', 'termmenus' ) );
				$form->append( 'hidden', 'menu' )->value = $handler->handler_vars[ 'menu' ];
				$form->append( 'submit', 'submit', _t( 'Add link', 'termmenus' ) );

				$form->on_success( array( $this, 'create_link_form_save' ) );
				$form->set_option( 'form_action', $form_action );
				break;

			case 'create_spacer':
				$form = new FormUI( 'create_spacer' );
				$form->class[] = 'tm_db_action';
				$form->append( 'text', 'spacer_text', 'null:null', _t( 'Item text (leave blank for blank space)', 'termmenus' ) );
				$form->append( 'hidden', 'menu' )->value = $handler->handler_vars[ 'menu' ];
				$form->append( 'submit', 'submit', _t( 'Add spacer', 'termmenus' ) );

				$form->on_success( array( $this, 'create_spacer_form_save' ) );
				$form->set_option( 'form_action', $form_action );
				break;

			case 'link_to_posts':
				$form = new FormUI( 'link_to_posts' );
				$form->class[] = 'tm_db_action';
				$post_ids = $form->append( 'text', 'post_ids', 'null:null', _t( 'Posts', 'termmenus' ) );
				$post_ids->template = 'text_tokens';
				$post_ids->ready_function = "$('#{$post_ids->field}').tokenInput( habari.url.ajaxPostTokens )";

				$form->append( 'hidden', 'menu' )->value = $handler->handler_vars[ 'menu' ];
				$form->append( 'submit', 'submit', _t( 'Add post(s)', 'termmenus' ) );

				$form->on_success( array( $this, 'link_to_posts_form_save' ) );
				$form->set_option( 'form_action', $form_action );
				break;
		}
		$form->properties['onsubmit'] = "$.post($('#{$action}').attr('action'), $('.tm_db_action').serialize(), function(data){\$('#menu_popup').html(data);});return false;";
		$theme->page_content = $form->get();
		if(isset($_GET['result'])) {
			switch($_GET['result']) {
				case 'added':
					$treeurl = URL::get( 'admin', array('page' => 'menus', 'menu' => $handler->handler_vars[ 'menu' ], 'action' => 'edit') ) . ' #edit_menu>*';
					$msg = _t( 'Menu item added.', 'termmenus' );
					$theme->page_content .= <<< JAVSCRIPT_RESPONSE
<script type="text/javascript">
human_msg.display_msg('{$msg}');
$('#edit_menu').load('{$treeurl}', function(){controls.init();});
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
		$action = isset($_GET[ 'action' ]) ? $_GET[ 'action' ] : 'list';
		switch( $action ) {
			case 'edit':
				$vocabulary = Vocabulary::get_by_id( intval( $handler->handler_vars[ 'menu' ] ) );
				if ( $vocabulary == false ) {
					$theme->page_content = _t( '<h2>Invalid Menu.</h2>', 'termmenus' );
					// that's it, we're done. Maybe we show the list of menus instead?
					break;
				}
				$top_url = URL::get( 'admin', 'page=menus' );
				$edit_link = URL::get( 'admin', array(
					'page' => 'menus',
					'action' => 'rename',
					'menu' => $vocabulary->id,
				) );

				$theme->page_content = _t( "<h2><a href='$top_url'>Menus</a>: Editing <b>{$vocabulary->name}</b></h2>", 'termmenus' );
				$theme->page_content .= _t( "<div id='menu_vocab'><b>{$vocabulary->name}</b> <em>{$vocabulary->description}</em><a class='menu_vocab_edit' title='Rename or modify description' href='$edit_link'>Edit</a></div>", 'termmenus' );
				$form = new FormUI( 'edit_menu' );

				if ( !$vocabulary->is_empty() ) {
					$form->append( 'tree', 'tree', $vocabulary->get_tree(), _t( 'Menu', 'termmenus') );
					$form->tree->config = array( 'itemcallback' => array( $this, 'tree_item_callback' ) );
//						$form->tree->value = $vocabulary->get_root_terms();
					// append other needed controls, if there are any.

					$form->append( 'submit', 'save', _t( 'Apply Changes', 'termmenus' ) );
				}
				else {
					$form->append( 'static', 'message', _t( '<h3>No links yet.</h3>', 'termmenus' ) );
				}
				$edit_items = '<div class="edit_menu_dropbutton"><ul class="dropbutton">' .
					'<li><a class="modal_popup_form" href="' . URL::get('admin', array(
						'page' => 'menu_iframe',
						'action' => 'link_to_posts',
						'menu' => $vocabulary->id,
					) ) . '">' . _t( 'Link to post(s)', 'termmenus' ) . '</a></li>' .
					'<li><a class="modal_popup_form" href="' . URL::get('admin', array(
						'page' => 'menu_iframe',
						'action' => 'create_link',
						'menu' => $vocabulary->id,
					) ) . '">' . _t( 'Add a link URL', 'termmenus' ) . '</a></li>' .
					'<li><a class="modal_popup_form" href="' . URL::get('admin', array(
						'page' => 'menu_iframe',
						'action' => 'create_spacer',
						'menu' => $vocabulary->id,
					) ) . '">' . _t( 'Add a spacer', 'termmenus' ) . '</a></li>' .
					'</ul></div><script type="text/javascript">' .
					'$("a.modal_popup_form").click(function(){$("#menu_popup").load($(this).attr("href")).dialog({title:$(this).text()}); return false;});</script>';
				$theme->page_content .= $form->get() . $edit_items;
				break;

			case 'create':

				$form = new FormUI('create_menu');
				$form->append( 'text', 'menuname', 'null:null', _t( 'Menu Name', 'termmenus' ) )
					->add_validator( 'validate_required', _t( 'You must supply a valid menu name', 'termmenus' ) )
					->add_validator( array($this, 'validate_newvocab' ) );
				$form->append( 'text', 'description', 'null:null', _t( 'Description', 'termmenus' ) );
				$form->append( 'submit', 'submit', _t( 'Create Menu', 'termmenus' ) );
				$form->on_success( array( $this, 'add_menu_form_save' ) );
				$theme->page_content = $form->get();

				break;

			case 'rename':
				$menu_vocab = Vocabulary::get_by_id( intval( $handler->handler_vars[ 'menu' ] ) );

				$form = new FormUI( 'modify_menu' );
				$form->append( 'text', 'menuname', 'null:null', _t( 'Menu Name', 'termmenus' ) )
					->add_validator( 'validate_required', _t( 'You must supply a valid menu name', 'termmenus' ) )
					->add_validator( array( $this, 'validate_newvocab' ) )
					->value = $menu_vocab->name;
				$form->append( 'text', 'description', 'null:null', _t( 'Description', 'termmenus' ) )
					->value = $menu_vocab->description;
				$form->append( 'hidden', 'menu' )->value = $handler->handler_vars[ 'menu' ];
				$form->append( 'hidden', 'oldname' )->value = $menu_vocab->name;
				$form->append( 'submit', 'submit', _t( 'Update Menu', 'termmenus' ) );
				$form->on_success( array( $this, 'rename_menu_form_save' ) );
				$theme->page_content = $form->get();

				break;

			case 'list':
				$menu_list = '';

				foreach ( $this->get_menus() as $menu ) {
					$edit_link = URL::get( 'admin', array(
						'page' => 'menus',
						'action' => 'edit',
						'menu' => $menu->id,
					) );
					$delete_link = URL::get( 'admin', array(
						'page' => 'menus',
						'action' => 'delete_menu',
						'menu' => $menu->id,
					) );
					$menu_name = $menu->name;
					// @TODO _t() this line or replace it altogether
					$menu_list .= "<li class='item'><a href='$edit_link'><b>$menu_name</b> {$menu->description} - {$menu->count_total()} items</a>" .
						" <a class='menu_item_delete' title='Delete this' href='$delete_link'>delete</a></li>";

				}
				if ( $menu_list != '' ) {
					$theme->page_content = _t( "<h2>Menus</h2><hr><ul id='menu_list'>$menu_list</ul>", 'termmenus' );
				}
				else {
					$edit_url = URL::get( 'admin', array( 'page' => 'menus', 'action' => 'create' ) );

					$theme->page_content = _t( "<h2>No Menus have been created.</h2><hr><p><a href='$edit_url'>Create a Menu</a></p>", 'termmenus' );
				}
				break;

			case 'delete_menu':
				$menu_vocab = Vocabulary::get_by_id( intval( $handler->handler_vars[ 'menu' ] ) );
				$menu_vocab->delete();
				// log that it has been deleted?
				Session::notice( _t( 'Menu deleted.', 'termmenus' ) );
				Utils::redirect( URL::get( 'admin', 'page=menus' ) );
				break;

			case 'delete_term':
				$term = Term::get( intval( $handler->handler_vars[ 'term' ] ) );
				$menu_vocab = $term->vocabulary_id;
				$term->delete();
				// log that it has been deleted?
				Session::notice( _t( 'Item deleted.', 'termmenus' ) );
				Utils::redirect( URL::get( 'admin', array( 'page' => 'menus', 'action' => 'edit', 'menu' => $menu_vocab ) ) );
				break;

			case 'edit_term':
				$term = Term::get( intval( $handler->handler_vars[ 'term' ] ) );
				$menu_vocab = $term->vocabulary_id;
				$term_type = '';
				foreach( $term->object_types() as $object_id => $type ) {
					// render_menu_item() does this as a foreach. I'm assuming there's only one type here, but I could be wrong. Is there a better way?
					$term_type = $object_id;
				}
				$form = new FormUI( 'edit_term' );
				$form->append( 'text', 'title', 'null:null', _t( 'Item Title', 'termmenus' ) )
					->add_validator( 'validate_required' )
					->value = $term->term_display;
				if ( $term_type == 'url' ) {
					$form->append( 'text', 'link_url', 'null:null', _t( 'Link URL', 'termmenus' ) )
						->add_validator( 'validate_required' )
						->add_validator( 'validate_url', _t( 'You must supply a valid URL.', 'termmenus' ) )
						->value = $term->info->url;
				}
				$form->append( 'submit', 'submit', _t( 'Apply Changes', 'termmenus' ) );
				$form->on_success( array( $this, 'edit_term_form_save' ) );
				$theme->page_content = $form->get();

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
			'description' => ( $form->description->value === '' ? _t( 'A vocabulary for the "%s" menu', array( $form->menuname->value ), 'termmenus' ) : $form->description->value ),
			'features' => array( 'term_menu' ), // a special feature that marks the vocabulary as a menu
		);
		$vocab = Vocabulary::create($params);
		Session::notice( _t( 'Created menu "%s".', array( $form->menuname->value ), 'termmenus' ) );
		Utils::redirect( URL::get( 'admin', 'page=menus' ));
	}

	public function rename_menu_form_save( $form )
	{
		// get the menu from the form, grab the values, modify the vocabulary.
		$menu_vocab = intval( $form->menu->value );
		// create a term for the link, store the URL
		$menu = Vocabulary::get_by_id( $menu_vocab );
		$menu->name = $form->menuname->value; // could use Vocabulary::rename for this
		$menu->description = $form->description->value; // no Vocabulary function for this
		$menu->update();

		Session::notice( _t( 'Updated menu "%s".', array( $form->menuname->value ), 'termmenus' ) );
		Utils::redirect( URL::get( 'admin', array(
			'page' => 'menus',
			'action' => 'edit',
			'menu' => $menu->id,
		) ) );
	}

	public function edit_term_form_save( $form )
	{
Utils::debug( $form );
	}

	public function link_to_posts_form_save( $form )
	{
		$menu_vocab = intval( $form->menu->value );
		// create a term for the link, store the URL
		$menu = Vocabulary::get_by_id( $menu_vocab );

		$post_ids = explode( ',', $form->post_ids->value );
		foreach( $post_ids as $post_id ) {
			$post = Post::get( array( 'id' => $post_id ) );
			$term_title = $post->title;

			$terms = $menu->get_object_terms( 'post', $post->id );
			if( count( $terms ) == 0 ) {
				$term = new Term( array( 'term_display' => $post->title, 'term' => $post->slug ) );
				$menu->add_term( $term );
				$menu->set_object_terms( 'post', $post->id, array( $term->term ) );
			}
		}
		Session::notice( _t( 'Link(s) added.', 'termmenus' ) );
		Utils::redirect(URL::get( 'admin', array(
			'page' => 'menu_iframe',
			'action' => 'link_to_posts',
			'menu' => $menu_vocab,
			'result' => 'added',
			) ) );
	}

	public function create_link_form_save( $form )
	{
		$menu_vocab = intval( $form->menu->value );
		// create a term for the link, store the URL
		$menu = Vocabulary::get_by_id( $menu_vocab );
		$term = new Term( array(
			'term_display' => $form->link_name->value,
			'term' => Utils::slugify( $form->link_name->value ),
			));
		$term->info->url = $form->link_url->value;
		$menu->add_term( $term );
		$term->associate( 'menu', $this->item_types[ 'url' ] );

		Session::notice( _t( 'Link added.', 'termmenus' ) );
		Utils::redirect(URL::get( 'admin', array(
			'page' => 'menu_iframe',
			'action' => 'create_link',
			'menu' => $menu_vocab,
			'result' => 'added',
			) ) );
	}

	public function create_spacer_form_save( $form )
	{
		$menu_vocab = intval( $form->menu->value );
		$menu = Vocabulary::get_by_id( $menu_vocab );
		$term = new Term( array(
			'term_display' => ( $form->spacer_text->value !== '' ? $form->spacer_text->value : '&nbsp' ), // totally blank values collapse the term display in the formcontrol
			'term' => Utils::slugify( ($form->spacer_text->value !== '' ? $form->spacer_text->value : 'menu_spacer' ) ),
			));
		$menu->add_term( $term );
		$term->associate( 'menu', $this->item_types[ 'spacer' ] );

		Session::notice( _t( 'Spacer added.', 'termmenus' ) );
		Utils::redirect(URL::get( 'admin', array(
			'page' => 'menu_iframe',
			'action' => 'create_spacer',
			'menu' => $menu_vocab,
			'result' => 'added',
			) ) );
	}

	public function validate_newvocab( $value, $control, $form )
	{
		if( ( $form->oldname->value ) && ( $value == $form->oldname->value ) ) {
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
	* Provide a method for listing the types of menu items that are available
	* @return array List of item types, keyed by name and having integer index values
	**/
	public function get_item_types()
	{
		return Plugins::filter( 'get_item_types', $this->item_types );
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
						'page' => 'menus',
						'action' => 'edit_term',
						'term' => $term->id,
					) );
		$delete_link = URL::get( 'admin', array(
						'page' => 'menus',
						'action' => 'delete_term',
						'term' => $term->id,
					) );

		// insert them into the wrapper
		// @TODO _t() this line or replace it altogether.
		$links = "<a class='menu_item_delete' title='Delete this' href='$delete_link'>delete</a> <a class='menu_item_edit' href='$edit_link'>edit</a>";
		$config[ 'wrapper' ] = "<div>%s $links</div>";

		return $config;
	}

	/**
	 * Callback function for block output of menu list item
	 **/
	public function render_menu_item( Term $term, $config )
	{
		$title = $term->term_display;
		$link = '';
		$objects = $term->object_types();

		$active = false;
		$spacer = false;
		foreach( $objects as $object_id => $type ) {
			switch( $type ) {
				case 'post':
					$post = Post::get( array( 'id' => $object_id ) );
					if( $post instanceof Post ) {
						$link = $post->permalink;
						if( $config[ 'theme' ]->posts instanceof Post && $config[ 'theme' ]->posts->id == $post->id ) {
							$active = true;
						}
					}
					else {
						// The post doesn't exist or the user does not have access to it
					}
					break;
				case 'menu':
					$item_types = $this->get_item_types();
					switch( $object_id ) {
						case $item_types[ 'url' ]:
							$link = $term->info->url;
							break;
						case $item_types[ 'spacer' ]:
							if ( empty( $term->term_display ) ) {
								$title = '&nbsp;';
							}
							$spacer = true;
							// no need to break, default below is just fine.
						default:
							$link = null;
							$link = Plugins::filter( 'get_item_link', $link, $term, $object_id, $type );
							break;
					}
					break;
			}
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
