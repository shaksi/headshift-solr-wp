<?php
/*
Plugin Name: Solr for WordPress
Plugin URI: #
Description: Indexes, removes, and updates documents in the Solr search engine.
Version: 0.3.0
Author Shakur Shidane
Original code by: Author: Matt Weber
*/
/*  
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
$version = '0.3.0';

$errmsg = __('Solr for WordPress requires WordPress 2.7 or greater. ', 'solr4wp');
if (version_compare($wp_version, '2.7', '<')) {
    exit ($errmsg);
}
require_once(WP_PLUGIN_DIR. '/solr-for-wordpress/inc/solr_config.php');
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
    //get author information
    $auth_info = get_userdata( $post_info->post_author );
    
    $doc->setField( 'id', $post_info->ID );
    $doc->setField( 'permalink', get_permalink( $post_info->ID ) );
    $doc->setField( 'title', $post_info->post_title );
    $doc->setField( 'content', strip_tags($post_info->post_content) );
    $doc->setField( 'numcomments', $post_info->comment_count );
    $doc->setField( 'author', $auth_info->display_name );
    $doc->setField( 'author_id', $post_info->post_author);
    $doc->setField( 'type', $post_info->post_type );
    $doc->setField( 'excerpt', $post_info->post_excerpt);
    
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
    $taxonomies = get_taxonomies(array('_builtin'=>FALSE),'names');
    foreach($taxonomies as $parent) {
      $terms = get_the_terms( $post_info->ID, $parent );
      if ((array) $terms === $terms) {
        //we are creating *_taxonomy as dynamic fields using our schema
        //so lets set up all our taxonomies in that format
        $parent = $parent."_taxonomy";
        foreach ($terms as $term) {
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
  } 
   catch ( Exception $e ) {
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
  } 
   catch ( Exception $e ) {
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
  } 
   catch ( Exception $e ) {
      echo $e->getMessage();
  }
}

function s4w_handle_modified( $post_id ) {
  $post_info = get_post( $post_id );
  //if required remove from index any posts whose changed its status
  s4w_handle_status_change( $post_info );
  $indexable_content = get_option( "s4w_content_index");
  //check the loaded post_type has been selected for indexed
  //and the post is not draft or private
  if (array_key_exists($post_info->post_type,$indexable_content) && 
      ($post_info->post_status != 'draft' || $post_info->post_status != 'private')) {
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
  $sql = "SELECT DISTINCT(post_type) 
          FROM $wpdb->posts 
          WHERE post_type NOT IN('attachment', 'revision', 'nav_menu_item') 
          ORDER BY post_type";
  $query = $wpdb->get_results($sql);
  if ($query) {
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

function s4w_search_results($qry = NULL, $offset = NULL, $count = NULL, $fq = NULL, $core = 0, $query_function = NULL, $required_facets=array()){
  $qry = ($qry)?$qry:stripslashes($_GET['s']);
  $offset = ($offset)?$offset:$_GET['offset'];
  $count = ($count)?$count:$_GET['count'];
  $fq = ($fq)?$fq :(stripslashes($_GET['fq']));
  
  if (!$core){
    $core = ($_GET['core']) ? $_GET['core'] : 0;
  }

  $output_info = get_option('s4w_output_info');
  $output_pager = get_option('s4w_output_pager');
  $output_facets = get_option('s4w_output_facets');
  
  if (count($required_facets) <1) {
    $required_facets = get_option('s4w_facets');  
  }
  $results_per_page = get_option('s4w_num_results');
  $categoy_as_taxonomy = get_option('s4w_cat_as_taxo');

  # set some default values
  if ( !$offset ) {
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

  $fqitms = split('\|\|', stripslashes($fq));
  

  if ($qry) {
    $results = s4w_query( $qry, $offset, $count, $fqitms, $sort, $core, $required_facets);
    if ($results) {
      $response = $results->response;
      $header = $results->responseHeader;
      $teasers = get_object_vars($results->highlighting);
      print(__('<section id="search-results">', 'solr4wp'));
      if ($output_info && !$query_function) {
        print(__('<h1 class="results-heading">Search results for <em>'.get_search_query().'</em></h1>', 'solr4wp'));
        $outinfo = __('<h2 id="resultinfo"><p>Found <em id="resultcnt">%d</em> results for <em id="resultqry">%s</em> in <em id="qrytime">%.3f</em> seconds.</p></h2>', 'solr4wp'); 
        printf($outinfo, $response->numFound, htmlspecialchars($qry), $header->QTime/1000);
      }
      print(__('<p></p>', 'solr4wp'));

      if ($response->numFound == 0) {
        printf(__('<div id="noresults">No Results Found</div>', 'solr4wp'));
      }
       else {         
        $query_function = ($query_function) ? $query_function : $core ;
        $function = 's4w_output_core_'.$query_function.'_row';
        foreach ( $response->docs as $doc ) {
          if(function_exists($function)) {
            $function($doc);
          }
           else {
             s4w_output_core_0_row($doc);
          }
        }
      }

      
        if ($output_pager) {
          s4w_search_pager($response, $qry, $count, $offset);
        }
      printf(__('</section>', 'solr4wp'));

    }
    if ($output_facets) {
      //print the facets block and the show the select facets if any
      s4w_search_facets($results, $qry, $fqitms);
    }
  } 
}




function s4w_search_pager($response, $qry, $count, $offset) {
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
        $itemout = '<li><a href="?s=%s&fq=%s&offset=%d&count=%d&core=%s">%d</a></li>';
        printf($itemout, urlencode($qry),htmlspecialchars(stripslashes($fq)),  $offsetnum, $count,$_GET['core'], $pagenum);
      } 
       else {
        printf(__('<li>%d</li>', 'solr4wp'), $pagenum);
      }
    }
    print(__('</ul></div><div id="pagerclear"></div>', 'solr4wp'));
  
}

/**
 * Outputs the fact block of the search
 * TODO Separate logics and presentation
 * @param results solr results object to which 
 *        the facets are extracted from
 * @param $fqitms array of selected facets
 */
function s4w_search_facets($results, $qry, $fqitms) {
  $fqstr = '';
  $query_values=array();
  foreach ((array)$fqitms as $fqitem) {
    if ($fqitem) {
      $splititm = split(':', $fqitem, 2);
      if($val = trim($splititm[1],'"\\')) {
        $query_values[] = $val;
      }
      $fqstr = $fqstr . urlencode('||') . $splititm[0] . ':' . urlencode($splititm[1]);
    }
  }  # handle facets
  print(__('<aside id="sidebar"><ul class="facets">', 'solr4wp'));

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
         if (is_numeric($qfacet = array_search($facetval ,$query_values))) {
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
         if (is_numeric($qfacet = array_search($facetval ,$query_values))) {
           $fqstrs  = trim($fqstr,urlencode('|'));
           $faceturl = sprintf(__('?s=%s&fq=%s&core=%s', 'solr4wp'), $qry, trim($fqstrs,urlencode('|')), $_GET['core']);
           $facet_url_replace = $facetfield.':'.urlencode('"' . $facetval . '"');
           $faceturl = str_replace($facet_url_replace, '', $faceturl);

           $faceturl = str_replace("=%7C%7C", "=", $faceturl);

           $result_facet_used[] = sprintf(__('%s<a href="%s"> [X]</a>','solr4wp'), $facetval, $faceturl);
           $facet_used = "facet_used";
         }
          else {
           $faceturl = sprintf(__('?s=%s&fq=%s:%s%s&core=%s', 'solr4wp'), $qry, $facetfield, urlencode('"' . $facetval . '"'), $fqstr,$_GET['core']);
           $facet_used = "facet_not_used";
         }
         printf(__('<li class="facetitem %s"><a href="%s">%s</a> (%d)</li>', 'solr4wp'), $facet_used, $faceturl,  ucfirst($facetval), $facetcnt);
       }
     }
     print(__('</ul></li>', 'solr4wp'));
   }
  }


  print(__('</ul>', 'solr4wp'));

  //show the used facets
  if (count($result_facet_used) > 0) {
   print '<div class="result_facets_used">';
   print '<p>To remove a search filter, click [x]</p>';
   foreach ($result_facet_used as $used_facet){
    print('<div class="result_facet_used">'.$used_facet.'</div>');
   }
   print '</div>';
  }
  print(__('</aside>', 'solr4wp'));
}

function s4w_output_core_0_row($doc){
  print(__('<article class="result">', 'solr4wp'));
  $titleout = __('<h2><a href="%s">%s</a></h2><h3>by <em id="resultauthor">%s</em> has a score of <em id="resultscore">%f</em></h3>', 'solr4wp');
  printf($titleout, $doc->permalink, $doc->title, $doc->author, $doc->get_avatar,$doc->score);
  $docid = strval($doc->id);
  $docteaser = $teasers[$docid];
  if ($docteaser->content) {
      printf(__('<p>...%s...</p></article>', 'solr4wp'), implode('...', $docteaser->content));
  } else {
      $words = split(' ', $doc->content);
      $teaser = implode(' ', array_slice($words, 0, 30));
      printf(__('<p>%s...</p></article>', 'solr4wp'), $teaser);
  }
}

function s4w_output_core_1_row($doc){
  print(__('<article class="result">', 'solr4wp'));
  $titleout = __('<h2><a href="%s" target="blank">%s</a></h2><h3> has a score of <em id="resultscore">%f</em></h3>', 'solr4wp');
  printf($titleout, $doc->url, $doc->desc, $doc->score);
  $words = split(' ', $doc->content);
  $teaser = implode(' ', array_slice($words, 0, 30));
  printf(__('<p>%s...</p></article>', 'solr4wp'), $teaser);
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

function s4w_query( $qry, $offset, $count, $fq, $sort=NULL, $core=0, $required_facets = null ) {
    $solr = s4w_get_solr($core);
		$function = 's4w_core_'.$core.'_query';
		return $function($solr,$qry, $offset, $count, $fq, $sort, $required_facets);
}

function s4w_core_0_query($solr, $qry, $offset, $count, $fq, $sort=NULL, $required_facets = NULL){
  $response = NULL;
  $facet_fields = array();
  $number_of_tags  = get_option('s4w_max_display_tags');
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
          $params['sort'] = "postdate_dt desc";
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

function s4w_core_1_query($solr, $qry, $offset, $count, $fq, $sort=NULL, $required_facets= NULL){
  $response = NULL;
  $facet_fields = array();
  $number_of_tags  = get_option('s4w_max_display_tags');
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
      $params['hl.fl'] = 'text';
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
        $qry = "*:*";
      }
      $response = $solr->search( $qry, $offset, $count, $params);
      if ( ! $response->getHttpStatus() == 200 ) { 
          $response = NULL; 
      }
  }
  return $response;
}




/**
*  Return number of results in solr index for a given facet and the time the query took
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

?>
