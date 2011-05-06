<?php
/*
Plugin Name: Solr for WordPress
Plugin URI: https://launchpad.net/solr4wordpress
Donate link: http://www.mattweber.org
Description: Indexes, removes, and updates documents in the Solr search engine.
Version: 0.2.0
Author: Matt Weber
Author URI: http://www.mattweber.org
*/
/*  
    Copyright (c) 2009 Matt Weber

    Permission is hereby granted, free of charge, to any person obtaining a copy
    of this software and associated documentation files (the "Software"), to deal
    in the Software without restriction, including without limitation the rights
    to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
    copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in
    all copies or substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
    IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
    FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
    AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
    LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
    THE SOFTWARE.
*/

global $wp_version, $version;
$version = '0.2.0';

$errmsg = __('Solr for WordPress requires WordPress 2.7 or greater. ', 'solr4wp');
if (version_compare($wp_version, '2.7', '<')) {
    exit ($errmsg);
}

require_once(WP_PLUGIN_DIR. '/solr-for-wordpress/Apache/Solr/Service.php');

function s4w_get_solr($core=0, $ping = false) {
    # get the connection options
    $host = get_option('s4w_solr_host');
    $port = get_option('s4w_solr_port');
    $path = get_option('s4w_solr_path');
    # double check everything has been set
    if ( ! ($host and $port and $path[$core]) ) {
        return NULL;
    }
    
    # create the solr service object
    $solr = new Apache_Solr_Service($host, $port, $path[$core]);
    # if we want to check if the server is alive, ping it
    if ($ping) {
        if ( ! $solr->ping() ) {
            $solr = NULL;
        }
    }
    
    return $solr;
}




/**
 * Returns the language code of a given post if WPML plugin is installed
 *
 * @param numeric post_id unique ID of a given post/page/resource etc
 * @return language_code (en, es etc) and language source if any
 **/
function s4w_get_post_language($post_id) {
  global $wpdb;
  if (function_exists('icl_get_languages')) {
    $query = sprintf("SELECT language_code, source_language_code FROM {$wpdb->prefix}icl_translations WHERE element_id='%d'", $post_id);
    $language = $wpdb->get_results($query);
    $output = $language[0];
  }
  else {
    $output->language_code = 'en';
  }
  return $output;
}

function s4w_build_document( $post_info ) {  

  $doc = NULL;
  $exclude_ids = get_option('s4w_content_exclude');
  $categoy_as_taxonomy = get_option('s4w_cat_as_taxo');
  
  if ($post_info) {
    
    # check if we need to exclude this document
    if ( in_array($post_info->ID, $exclude_ids) ) {
      return NULL;
    }
    $doc = new Apache_Solr_Document();
    $auth_info = get_userdata( $post_info->post_author );
    
    $doc->setField( 'id', $post_info->ID );
    $doc->setField( 'permalink', get_permalink( $post_info->ID ) );
    $doc->setField( 'title', $post_info->post_title );
    $doc->setField( 'content', strip_tags($post_info->post_content) );
    $doc->setField( 'numcomments', $post_info->comment_count );
    $doc->setField( 'author', $auth_info->display_name );
    $doc->setField( 'type', $post_info->post_type );
    //transform wordpress post into ISO8601
    // $iso_date = "2010-10-04T12:30:21Z";
    $strdate = strtotime($post_info->post_date);
    $iso_date = gmdate("Y-m-d", $strdate)."T".gmdate("H:i:s", $strdate)."Z";  
    $doc->setfield( 'postdate_dt',  $iso_date);
    
    $language = s4w_get_post_language($post_info->ID);
    
    $doc->setfield( 'language',  $language->language_code);
    
    $categories = get_the_category($post_info->ID);
    if ( !$categories == NULL ) {
      foreach( $categories as $category ) {
        if ($categoy_as_taxonomy) {
          $doc->addField( 'categories', get_category_parents($category->cat_ID, false, '^^'));
        } 
        else {
          $doc->addField( 'categories', $category->cat_name);
        }
      }
    }
    //get all the taxonomy names used by wp
    //$taxonomies = get_taxonomies('','names');
    $taxonomies = get_taxonomies(array('_builtin'=>FALSE),'names');
    foreach($taxonomies as $parent) {
      $terms = get_the_terms( $post_info->ID, $parent );
      if ((array) $terms === $terms) {
        foreach ($terms as $term) {
          $parent = $parent."_taxonomy";
          $doc->addField($parent, $term->name);
        }
      }
    }
      
    $tags = get_the_tags($post_info->ID);
    if ( ! $tags == NULL ) { 
      foreach( $tags as $tag ) {
        $doc->addField('tags', $tag->name);
      }
    }
  } 
   else {
    _e('Post Information is NULL', 'solr4wp');
  }
  
  return $doc;
}

function s4w_post( $documents ) { 
    try {
        $solr = s4w_get_solr();
        if ( ! $solr == NULL ) {
            $solr->addDocuments( $documents );
            $solr->commit();
            return true;
        }
    } catch ( Exception $e ) {
        echo $e->getMessage();
    }
    
}

function s4w_delete( $doc_id ) {
    try {
        $solr = s4w_get_solr();
        if ( ! $solr == NULL ) {
            $solr->deleteById( $doc_id );
            $solr->commit();
        }
    } catch ( Exception $e ) {
        echo $e->getMessage();
    }
}

function s4w_delete_all() {
    try {
        $solr = s4w_get_solr();
        if ( ! $solr == NULL ) {
            $solr->deleteByQuery( '*:*' );
            $solr->commit();
        }
    } catch ( Exception $e ) {
        echo $e->getMessage();
    }
}

function s4w_handle_modified( $post_id ) {
    $post_info = get_post( $post_id );
    //if required remove from index any posts whose changed its status
    s4w_handle_status_change( $post_info );
    $indexable_content = get_option( "s4w_content_index");
    //check the loaded post_type has been selected for indexed
    if (array_key_exists($post_info->post_type,$indexable_content)) {
        $docs = array();
        $doc = s4w_build_document( $post_info );
        if ( $doc ) {
            $docs[] = $doc;
            s4w_post( $docs );
        }
    }
}

function s4w_handle_status_change( $post_info ) {
    //$post_info = get_post( $post_id );
    $private_content = get_option('s4w_content_private');
    //which content type should be removed from the index if it's status changes
    if (array_key_exists($post_info->post_type,$private_content)) {
        if ( ($_POST['prev_status'] == 'publish' || $_POST['original_post_status'] == 'publish') && 
                ($post_info->post_status == 'draft' || $post_info->post_status == 'private') ) {
                    s4w_delete( $post_info->ID );
        }
    }
}

function s4w_handle_delete( $post_id ) {
    $post_info = get_post( $post_id );
    $delete_content = get_option('s4w_content_delete');
    //which content type should be removed from the index if it's deleted
    if (array_key_exists($post_info->post_type,$delete_content)) {
        s4w_delete( $post_info->ID );
    }
}

function s4w_load_all_content($type) {
  global $wpdb;
  //i dont know how wordpress sanitises data 
  //so i am going to compare the passed values to something i know is safe
  $indexable_content = get_option( "s4w_content_index");
  $indexable_type = array_keys($indexable_content);
  $where_and = " AND post_type = '$type'";

  if (!in_array($type,$indexable_type) && $type !='all') { 
  return false;
   
  }
  $where_and = ($type =='all') ?"AND post_type IN ('".implode("', '", $indexable_type). "')": " AND post_type = '$type'";
  $posts = $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_status='publish' $where_and" );
  if ( $posts ) {
    $documents = array();
    foreach ( $posts as $post ) {
        $documents[] = s4w_build_document( get_post($post->ID) );
    } 
    return s4w_post( $documents );
  }
}

function s4w_get_all_post_types() {
    global $wpdb;
    $query = $wpdb->get_results("SELECT DISTINCT(post_type) FROM $wpdb->posts WHERE post_type NOT IN('attachment', 'revision', 'nav_menu_item') ORDER BY post_type");
    if ( $query ) {
        $types = array();
        foreach ( $query as $type ) {
            $types[] = $type->post_type;
        }       
        return $types;
    }
}

function s4w_search_form() {
   $form = '<form name="searchbox" method="get" id="searchbox" action="">
                <input type="text" id="qrybox" name="s" value="%s"/>
                <input type="hidden" id="query" name="fq" value="%s"/>
                <input type="hidden" id="offset" name="offset" value="%s"/>
                <input type="hidden" id="count" name="count" value="%s"/>
                <input type="submit" id="searchbtn" />
              </form>';
    $output= __($form, 'solr4wp');
    $chkquery = ($_POST['chkquery']==1)?'checked="yes"':'';

    printf($output, stripslashes($_GET['s']), htmlspecialchars($_GET['fq']), $_GET['offset'], $_GET['count'], $chkquery);
}

function s4w_search_results() {

  $qry = stripslashes($_GET['s']);
  $offset = $_GET['offset'];
  $count = $_GET['count'];
  $fq = (stripslashes($_GET['fq']));

  $output_info = get_option('s4w_output_info');
  $output_pager = get_option('s4w_output_pager');
  $output_facets = get_option('s4w_output_facets');
  $results_per_page = get_option('s4w_num_results');
  $categoy_as_taxonomy = get_option('s4w_cat_as_taxo');

  # set some default values
  if ( ! $offset ) {
      $offset = 0;
  }

  # only use default if not specified in post information
  if ( ! $count ) {
      $count = $results_per_page;
  }

  if ( ! $fq ) {
      $fq = '';
  }
  
  if ( ! $qry ) {
      $qry = '*';
  }

  $fqstr = '';
  $fqitms = split('\|\|', stripslashes($fq));
  $query_values=array();
  foreach ($fqitms as $fqitem) {
      if ($fqitem) {
          $splititm = split(':', $fqitem, 2);
          if($val = trim($splititm[1],'"\\')) {
            $query_values[] = $val;
          }
        
          $fqstr = $fqstr . urlencode('||') . $splititm[0] . ':' . urlencode($splititm[1]);
      }
  }

  if ($qry) {
      $results = s4w_query( $qry, $offset, $count, $fqitms, $sort );
      if ($results) {
          $response = $results->response;
          $header = $results->responseHeader;
          $teasers = get_object_vars($results->highlighting);
        
          // if ($output_info) {
          //   $outinfo = __('<div id="resultinfo"><p>Found <em id="resultcnt">%d</em> results for <em id="resultqry">%s</em> in <em id="qrytime">%.3f</em> seconds.</p></div><div id="infoclear"></div>', 'solr4wp'); 
          //   printf($outinfo, $response->numFound, htmlspecialchars($qry), $header->QTime/1000);
          // }
          //         
       
        
          if ($output_facets) {
            
            # handle facets
            print(__('<aside id="facets"><ul class="facets">', 'solr4wp'));
            if($results->facet_counts) {
              foreach ($results->facet_counts->facet_fields as $facetfield => $facet) {
                if ( ! get_object_vars($facet) ) {
                  continue;
                }
                $facetfield_mod = trim(ucwords(str_replace(array('_taxonomy','_dt','_'),' ',$facetfield)));
                printf(__('<li class="facet"><h3>%s</h3><ul class="facetitems">', 'solr4wp'), $facetfield_mod);
                # categories is a taxonomy
                if ($categoy_as_taxonomy && $facetfield == 'categories') {
                  # generate taxonomy and counts
                  $taxo = array();
                  foreach ($facet as $facetval => $facetcnt) {
                    $taxovals = explode('^^', rtrim($facetval, '^^'));
                    $taxo = s4w_gen_taxo_array($taxo, $taxovals);
                    $facet_used = "facet_not_used";
                    //create a list of facets used in the current search and show [X] next to it
                    if(is_numeric($qfacet = array_search($facetval ,$query_values))) {
                      $fqstrs  = trim($fqstr,urlencode('|'));
                      $faceturl = sprintf(__('?s=%s&fq=%s', 'solr4wp'), $qry, trim($fqstrs,urlencode('|')));
                      $facet_url_replace = $facetfield.':'.urlencode('"' . $facetval . '"');
                      $faceturl = str_replace($facet_url_replace, '', $faceturl);

                      $faceturl = str_replace("=%7C%7C", "=", $faceturl);

                      $result_facet_used[] = sprintf(__('%s<a href="%s"> [X]</a>','solr4wp'), rtrim($facetval,'^'), $faceturl);
                      $facet_used = "facet_used";
                     }
                  }
                  s4w_print_taxo($facet, $taxo, '', $fqstr, $facetfield, $facet_used);
                } 
                 else {
                  foreach ($facet as $facetval => $facetcnt) {
                    //if the facet is being/not used put the appropriate class with it
                    if(is_numeric($qfacet = array_search($facetval ,$query_values))) {
                      $fqstrs  = trim($fqstr,urlencode('|'));
                      $faceturl = sprintf(__('?s=%s&fq=%s', 'solr4wp'), $qry, trim($fqstrs,urlencode('|')));
                      $facet_url_replace = $facetfield.':'.urlencode('"' . $facetval . '"');
                      $faceturl = str_replace($facet_url_replace, '', $faceturl);

                      $faceturl = str_replace("=%7C%7C", "=", $faceturl);

                      $result_facet_used[] = sprintf(__('%s<a href="%s"> [X]</a>','solr4wp'), $facetval, $faceturl);
                      $facet_used = "facet_used";
                     }
                      else {
                       $faceturl = sprintf(__('?s=%s&fq=%s:%s%s', 'solr4wp'), $qry, $facetfield, urlencode('"' . $facetval . '"'), $fqstr);
                       $facet_used = "facet_not_used";
                    }
                  printf(__('<li class="facetitem %s"><a href="%s">%s</a> (%d)</li>', 'solr4wp'), $facet_used, $faceturl,  ucfirst($facetval), $facetcnt);
                  }
                }
                print(__('</ul></li>', 'solr4wp'));
              }
            }
          }
        
          print(__('</ul></aside>', 'solr4wp'));
        
          //show the used facets
          if(count($result_facet_used)>0) {
            print '<div class="result_facets_used">';
  					print '<p>To remove a search filter, click [x]</p>';
            foreach ($result_facet_used as $used_facet){
              print('<div class="result_facet_used">'.$used_facet.'</div>');
            }
            print '</div>';
          }
        
          print(__('<section id="search-results">', 'solr4wp'));
        
          if ($response->numFound == 0) {
              printf(__('<div id="noresults">No Results Found</div>', 'solr4wp'));
          } else {
                          
              foreach ( $response->docs as $doc ) {
                  print(__('<article class="result">', 'solr4wp'));
                  $titleout = __('<h2><a href="%s">%s</a></h2><h3>by <em id="resultauthor">%s</em> has a score of <em id="resultscore">%f</em></h3>', 'solr4wp');
                  printf($titleout, $doc->permalink, $doc->title, $doc->author, $doc->score);
                  $docid = strval($doc->id);
                  $docteaser = $teasers[$docid];
                  if ($docteaser->content) {
                      printf(__('<p>...%s...</p></div>', 'solr4wp'), implode('...', $docteaser->content));
                  } else {
                      $words = split(' ', $doc->content);
                      $teaser = implode(' ', array_slice($words, 0, 30));
                      printf(__('<p>%s...</p></article>', 'solr4wp'), $teaser);
                  }
              }
          }

  				if ($output_pager) {      
                # calculate the number of pages
                $numpages = ceil($response->numFound / $count);
                $currentpage = ceil($offset / $count) + 1;

                if ($numpages == 0) {
                    $numpages = 1;
                }

                print(__('<div id="resultpager"><ul>', 'solr4wp'));
                foreach (range(1, $numpages) as $pagenum) {
                  if ( $pagenum != $currentpage ) {
                      $offsetnum = ($pagenum - 1) * $count;
                      $pagenum = __($pagenum, 'solr4wp');
                      $itemout = '<li><a href="?s=%s&fq=%s&offset=%d&count=%d">%d</a></li>';
                      
                      printf($itemout, urlencode($qry),htmlspecialchars(stripslashes($fq)),  $offsetnum, $count, $pagenum);
                  } 
                   else {
                      printf(__('<li>%d</li>', 'solr4wp'), $pagenum);
                  }
                }
                print(__('</ul></div><div id="pagerclear"></div>', 'solr4wp'));
            }

          print(__('</section>', 'solr4wp'));

      }
  } 
}

function s4w_print_taxo($facet, $taxo, $prefix, $fqstr, $field, $facet_used='facet_not_used') {
    
    $qry = stripslashes($_GET['s']);
    
    if (count($taxo) == 0) {
        return;
    } else {
        if ($prefix) {
            print (__('<li><ul class="facetchildren">', 'solr4wp'));
        }
        
        foreach ($taxo as $taxoname => $taxoval) {
            $newprefix = $prefix . $taxoname . '^^';
            $facetvars = get_object_vars($facet);
            $faceturl = sprintf(__('?s=%s&fq=%s:%s%s', 'solr4wp'), $qry, $field,  urlencode('"' . $newprefix . '"'), $fqstr);
            printf(__('<li class="facetitem %s"><a href="%s">%s</a> (%d)</li>', 'solr4wp'), $facet_used, $faceturl, $taxoname, $facetvars[$newprefix]);
            s4w_print_taxo($facet, $taxoval, $newprefix, $fqstr, $field);
        }
        
        if ($prefix) {
            print (__('</ul></li>', 'solr4wp'));
        }
    }
}

function s4w_gen_taxo_array($in, $vals) {
    if (count($vals) == 1) {
        if ( ! $in[$vals[0]]) {
            $in[$vals[0]] = array();
        }
        return $in;
    } else {
        $in[$vals[0]] = s4w_gen_taxo_array($in[$vals[0]], array_slice($vals, 1));
        return $in;
    }
}

function s4w_query( $qry, $offset, $count, $fq, $sort=NULL) {
    $solr = s4w_get_solr();
    $response = NULL;
    $facet_fields = array();
    $number_of_tags  = get_option('s4w_max_display_tags');
    $required_facets = get_option('s4w_facets');  
    $facet_fields    = array_keys($required_facets );  
    if ( $solr ) {
        $params = array();
        $qry = $solr->escape($qry);
        $params['facet'] = 'true';
        $params['facet.field'] = $facet_fields;
        $params['facet.mincount'] = '1';
        $params['fq'] = $fq;
        $params['fl'] = '*,score';
        $params['hl'] = 'on';
        $params['hl.fl'] = 'content';
        $params['hl.snippets'] = '3';
        $params['hl.fragsize'] = '50';
        //set the sort value for a given search if it's requested
        if(strlen($sort)>0) {
          $params['sort'] = $sort;
        }
        
        if ($number_of_tags) {
            $params['facet.limit']  = $number_of_tags;
        }
        if (trim($qry,"\\") == "*") {
          $finalqry = "*:*";
          //if there is no search time and sort order has not been specified
          //order the output by postdate
          if(strlen($sort)<1) {
            $params['sort'] = "postdate_dt asc";
          }
        }
        else {
          $finalqry = 'tagssrch: ' . $qry . '^4 title:' . $qry . '^3 categoriessrch:' . $qry . '^1.2 text:' . $qry . '^1';
        }
        $response = $solr->search( $finalqry, $offset, $count, $params);
        if ( ! $response->getHttpStatus() == 200 ) { 
            $response = NULL; 
        }
    }
    return $response;
}

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
    if ( file_exists (WP_PLUGIN_DIR. '/solr-for-wordpress/solr-options-page.php' )) {
        include_once(WP_PLUGIN_DIR. '/solr-for-wordpress/solr-options-page.php' );
        include_once(WP_PLUGIN_DIR. '/solr-for-wordpress/solr-for-wordpress-admin-ui.php' );
    } else {
        _e("<p>Couldn't locate the options page.</p>", 'solr4wp');
    }
}

function s4w_default_head() {
    // include our default css 
    if (file_exists(WP_PLUGIN_DIR . '/solr-for-wordpress/template/search.css')) {
        include_once(WP_PLUGIN_DIR . '/solr-for-wordpress/template/search.css');
    }

    
}

function s4w_mlt_widget() {
    register_widget('s4w_MLTWidget');
}
add_action('widgets_init', 's4w_mlt_widget');

class s4w_MLTWidget extends WP_Widget {

    function s4w_MLTWidget() {
        $widget_ops = array('classname' => 'widget_s4w_mlt', 'description' => __( "Displays a list of pages similar to the page being viewed") );
        $this->WP_Widget('mlt', __('Similar'), $widget_ops);
    }

    function widget( $args, $instance ) {
        
        extract($args);
        $title = apply_filters('widget_title', empty($instance['title']) ? __('Similar') : $instance['title']);
        $count = empty($instance['count']) ? 5 : $instance['count'];
        if (!is_numeric($count)) {
            $count = 5;
        }
        
        $showauthor = $instance['showauthor'];

        $solr = s4w_get_solr();
        $response = NULL;

        if ((!is_single() && !is_page()) || !$solr) {
            return;
        }
        
        $params = array();
        $qry = 'permalink:' . $solr->escape(get_permalink());
        $params['fl'] = 'title,permalink,author';
        $params['mlt'] = 'true';
        $params['mlt.count'] = $count;
        $params['mlt.fl'] = 'title,content';

        $response = $solr->search($qry, 0, 1, $params);
        if ( ! $response->getHttpStatus() == 200 ) { 
            return;
        }
    
        echo $before_widget;
        if ( $title )
            echo $before_title . $title . $after_title;
        
        $mltresults = $response->moreLikeThis;
        foreach ($mltresults as $mltresult) {
            $docs = $mltresult->docs;
            echo "<ul>";
            foreach($docs as $doc) {
                if ($showauthor) {
                    $author = " by {$doc->author}";
                }
                echo "<li><a href=\"{$doc->permalink}\" title=\"{$doc->title}\">{$doc->title}</a>{$author}</li>";
            }
            echo "</ul>";
        }
        
        echo $after_widget;
    }

    function update( $new_instance, $old_instance ) {
        $instance = $old_instance;
        $new_instance = wp_parse_args( (array) $new_instance, array( 'title' => '', 'count' => 5, 'showauthor' => 0) );
        $instance['title'] = strip_tags($new_instance['title']);
        $cnt = strip_tags($new_instance['count']);
        $instance['count'] = is_numeric($cnt) ? $cnt : 5;
        $instance['showauthor'] = $new_instance['showauthor'] ? 1 : 0;
        
        return $instance;
    }

    function form( $instance ) {
        $instance = wp_parse_args( (array) $instance, array( 'title' => '', 'count' => 5, 'showauthor' => 0) );
        $title = strip_tags($instance['title']);
        $count = strip_tags($instance['count']);
        $showauthor = $instance['showauthor'] ? 'checked="checked"' : '';
?>
            <p>
                <label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label>
                <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" />
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('count'); ?>"><?php _e('Count:'); ?></label>
                <input class="widefat" id="<?php echo $this->get_field_id('count'); ?>" name="<?php echo $this->get_field_name('count'); ?>" type="text" value="<?php echo esc_attr($count); ?>" />
            </p>
            <p>
                <label for="<?php echo $this->get_field_id('showauthor'); ?>"><?php _e('Show Author?:'); ?></label>
                <input class="checkbox" type="checkbox" <?php echo $showauthor; ?> id="<?php echo $this->get_field_id('showauthor'); ?>" name="<?php echo $this->get_field_name('showauthor'); ?>" />
            </p>
<?php
    }
}



function s4w_template_redirect() {
    // not a search page; don't do anything and return
    // thanks to the Better Search plugin for the idea:  http://wordpress.org/extend/plugins/better-search/
    if ( stripos($_SERVER['REQUEST_URI'], '?s=') == false || $_GET['type'] =='google') {
        return;
    }
    
    // If there is a template file then we use it
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

add_action( 'template_redirect', 's4w_template_redirect', 1 );
add_action( 'edit_post', 's4w_handle_status_change' );
add_action( 'save_post', 's4w_handle_modified' );
add_action( 'delete_post', 's4w_handle_delete' );
add_action( 'admin_menu', 's4w_add_pages');

if (isset($_GET['page'])) { 
    if ($_GET['page'] == "solr-for-wordpress/solr-for-wordpress.php") {
        add_action( 'admin_init', 's4w_options_init');
    }
}


/**
*  Return number of results in solr index fora  given facet and the time the query took
*
* @param array $fqitms in the format array('facetname1:"facetval1"','facetname2:"facetval2"') 
* @param string $searchtxt any given txt to search, leave empty for wildcard usage
* @return object with status, msg, items & time
**/

function s4w_get_facet_result_count($fqitms, $searchtxt="") {
   $output = new stdclass();
   $output->status = FALSE; 
  //if no facet items have been supplied return appropriate msg
  if(count($fqitms)>0) {
    //if no qry is given send wildcard ("*") as the search txt
    $qry = (strlen($qry)>0)?$searchtxt:"*";
    //order the result by post date DESC
    $sort = "postdate desc";
    //query solr
    $results = s4w_query($qry, 0, 0, $fqitms, $sort);
    if ($results) {
      $output->status      = TRUE; 
      $output->itemsfound  = $results->response->numFound;
      $output->time        = $results->responseHeader->QTime/1000;
    }
     else { // no result returned
      $output->msg    = "No result";
    }
  }
  else { //no facet items provided
    $output->msg    = 'No query facets provided';
  }
  return $output;
}

function solr_query_ruby_core($qry, $fq) {
     $solr = s4w_get_solr();
     $response = NULL;

      
      $params = array();
      //$qry =  $solr->escape($qry);
  

      $params['facet'] = 'true';
      $params['facet.field']='source';
      $params['fq'] = $fq;
      $results = $solr->search($qry, 0, 100, $params);
      if ( ! $results->getHttpStatus() == 200 ) { 
          return;
      }
  
      echo $before_widget;
      if ( $title )
          echo $before_title . $title . $after_title;
      $mltresults = $response->moreLikeThis;
      
       if($results->facet_counts) {
          foreach ($results->facet_counts->facet_fields as $facetfield => $facet) {
           foreach ($facet as $facetval => $facetcnt) {
             $facets .= '<a href=\''.$_SERVER["REQUEST_URI"].'&fq=source:"'.$facetval.'"\'>'.$facetval.' ('.$facetcnt.')</a><br />';
           }
          }
        }
      print "<p>$facets</p>";
      $response = $results->response;
        if ($response->numFound == 0) {
          printf(__('<div id="noresults">No Results Found</div>', 'solr4wp'));
        } 
         else {     
           $i=0;                   
          foreach ( $response->docs as $doc ) {
            $i++;
            print(__('<div class="result">', 'solr4wp'));
            if($doc->url) {
              print '<h3><a href="'.$doc->url.'">'.$doc->desc.'</a></h3>';
            }
             else {
               print "<h3>$doc->desc</h3>";
             }
            $docid = strval($doc->id);
            $docteaser = $teasers[$docid];
            if ($docteaser->content) {
                printf(__('<p>...%s...</p></div>', 'solr4wp'), implode('...', $docteaser->content));
            } 
             else {
                $words = split(' ', @implode('<br />',$doc->data));
                $teaser = implode(' ', array_slice($words, 0, 30));
                printf(__('<p>%s</p></div>', 'solr4wp'), $teaser);
            }
          }
        }
}

?>
