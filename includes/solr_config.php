<?php


function s4w_options_init() {
    register_setting( 's4w-options-group', 's4w_solr_host', 'wp_filter_nohtml_kses' );
    register_setting( 's4w-options-group', 's4w_solr_port', 'absint' );
    //register_setting( 's4w-options-group', 's4w_solr_path', 'wp_filter_nohtml_kses' );
    
 
    register_setting( 's4w-options-group', 's4w_private_post', 'wp_filter_nohtml_kses' );
    
    register_setting( 's4w-options-group', 's4w_output_info', 'absint' ); 
    register_setting( 's4w-options-group', 's4w_output_pager', 'absint' ); 
    register_setting( 's4w-options-group', 's4w_output_facets', 'absint' );
    register_setting( 's4w-options-group', 's4w_content_exclude', 's4w_filter_str2list_numeric' );
    register_setting( 's4w-options-group', 's4w_num_results', 'absint' );
    register_setting( 's4w-options-group', 's4w_cat_as_taxo', 'absint' );
    register_setting( 's4w-options-group', 's4w_max_display_tags', 'absint' );

    
    //add js files
    wp_enqueue_script('ui-s4w-admin',WP_PLUGIN_URL . '/solr-for-wordpress/js/s4w-admin.js');
    wp_enqueue_script('ui-s4w-admin',WP_PLUGIN_URL . '/solr-for-wordpress/js/jquery.urldecoder.js');
    wp_enqueue_script('jquery-ui-tabs',WP_PLUGIN_URL . '/solr-for-wordpress/js/jquery.ui.tabs.js');
    
    $my_css = WP_PLUGIN_DIR . '/solr-for-wordpress/css/s4w-admin-ui.css';
    $my_css_file = WP_PLUGIN_URL . '/solr-for-wordpress/css/s4w-admin-ui.css';
		if ( file_exists($my_css) ) {
		    wp_register_style('s4w-admin-ui', $my_css_file);
		    wp_enqueue_style( 's4w-admin-ui');
		    wp_register_style('jquery-ui-tabs-css',  WP_PLUGIN_URL . '/solr-for-wordpress/css/jquery-ui-1.8.10.custom.css');
		    wp_enqueue_style( 'jquery-ui-tabs-css');
		}
}

function s4w_filter_str2list_numeric($input) {
    $final = array();
    foreach( split(',', $input) as $val ) {
        $val = trim($val);
        if ( is_numeric($val) ) {
            $final[] = $val;
        }
    }

    return $final;
}

function s4w_filter_str2list($input) {
    $final = array();
    foreach( split(',', $input) as $val ) {
      $final[] = trim($val);
    }

    return $final;
}

function s4w_filter_list2str($input) {
  if(count($input)>0) {
    $output =  @implode(',', $input);
  }
  return $output;
}

function s4w_add_pages() {
  add_options_page('Solr Options', 'Solr Options', 8, __FILE__, 's4w_options_page');
}

function s4w_options_page() {
  if ( file_exists (WP_PLUGIN_DIR. '/solr-for-wordpress/includes/options_page.php' )) {
    include_once(WP_PLUGIN_DIR. '/solr-for-wordpress/includes/options_page.php' );
    include_once(WP_PLUGIN_DIR. '/solr-for-wordpress/includes/admin_ui.php' );
  } 
   else {
     _e("<p>Couldn't locate the options page.</p>", 'solr4wp');
  }
}

function s4w_default_head() {
    // include our default css 
    if (file_exists(WP_PLUGIN_DIR . '/solr-for-wordpress/template/search.css')) {
        include_once(WP_PLUGIN_DIR . '/solr-for-wordpress/template/search.css');
    }

    
}

function s4w_template_redirect() {
    // not a search page; don't do anything and return
    // thanks to the Better Search plugin for the idea:  http://wordpress.org/extend/plugins/better-search/
    if ( stripos($_SERVER['REQUEST_URI'], '?s=') == false || $_GET['type'] =='google' ) {
        return;
    }
    
    if (file_exists(TEMPLATEPATH . '/s4w_search.php')) {
        // use theme file
        include_once(TEMPLATEPATH . '/s4w_search.php');
    } else if (file_exists(WP_PLUGIN_DIR. '/solr-for-wordpress/template/s4w_search.php')) {
        // use plugin supplied file
        add_action('wp_head', 's4w_default_head');
        include_once(WP_PLUGIN_DIR. '/solr-for-wordpress/template/s4w_search.php');
    } else {
        // no template files found, just continue on like normal
        // this should get to the normal WordPress search results
        return;
    }
    
    exit;
}

//add js files
add_action('template_redirect', 's4w_template_redirect', 1 );
add_action('edit_post', 's4w_handle_status_change' );
add_action('save_post', 's4w_handle_modified' );
add_action('delete_post', 's4w_handle_delete' );
add_action('trashed_post','s4w_handle_delete');
add_action('admin_menu', 's4w_add_pages');

if (isset($_GET['page'])) { 
  if ($_GET['page'] == "solr-for-wordpress/includes/solr_config.php") {
    add_action( 'admin_init', 's4w_options_init');
  }
}




?>