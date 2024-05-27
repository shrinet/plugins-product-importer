<?php
/*
 * Plugin Name: MacleanProductImporter
 * Author: Akhilesh Singh
 * Version: 1.0.0
 * Author URI: https://www.asshrinet.com
 * Description: Import CRM products and customers
 */

function mvs_product_importer_deactivate()
{

}
register_deactivation_hook(__FILE__, 'mvs_product_importer_deactivate');

global $mvs_product_importer;
$mvs_product_importer = new MvsProductImporter();

class MvsProductImporter
{

    public function __construct()
    {
        $this->setup_actions();
        $this->enqueue_scripts();
    }

    public function setup_actions()
    {
        add_action('admin_menu', array($this, 'setup_menus'));
        add_action('admin_head', array($this, 'admin_js_globals'));
        add_action('wp_ajax_import_data', array($this, 'import_data'));
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script('mvs_product_importer-default-script', plugin_dir_url(__FILE__) . 'assets/js/public.js', array('jquery'));
    }

    public function setup_menus()
    {
        add_menu_page( 'Mvs Product Importer', 'Mvs Product Importer', 'manage_options', 'mvs_product_importer', array( $this, 'mvs_product_importer_page' ), '', 6  );
    }

    public function mvs_product_importer_page() {
		ob_start();
		if ( array_key_exists( 'upload_message', $_GET ) ) {
			?>
				<script>
					alert("<?php echo $_GET[ 'upload_message' ]; ?>");
					window.location = "<?php echo get_admin_url() . 'admin.php?page=mvs_product_importer'; ?>";
				</script>
			<?php
		}
		?>
		<div class='import-container'>
			<h1>Upload File</h1>
			<form action="<?php echo plugin_dir_url( __FILE__ ) . 'upload.php'; ?>" method="post" enctype="multipart/form-data">
				Select csv files to upload for import:
				<input type="file" name="fileToUpload[]"  multiple="multiple" id="fileToUpload">
                <br/>
                Is Zip File?
                <input type="checkbox" name="isZipFile" />
                <br/>
                Should the importer split the file up into smaller files?:
                <input type="checkbox" name="shouldSplitFiles" />
                <br/>
                Should the importer preserve headers?:
                <input type="checkbox" name="shouldPreserveHeaders" />
                <br/>
                If yes to the above, how many rows per sub file?:
                <input type="number" name="splitFileRowCount" value="500" />
                <br/>
                Extra Folder name?
                <input type="text" name="extra_folder" id="extraFolder"/>
                <br/>
                Import products:
                <input type="checkbox" name="import_folder" id="importProducts" value="product" />
                <br/>
                Import categories:
                <input type="checkbox" name="import_folder" id="importCategories" value="category"/>
                <br/>
                Import category metadata:
                <input type="checkbox" name="import_folder" id="importCategoriesMeta" value="category_metadata"/>
                <br/>
                Reset category permalinks:
                <input type="checkbox" name="import_folder" id="importPermalinks" value="permalink"/>
                <br/>
                Export categories:
                <input type="checkbox" name="import_folder" id="exportCategories" value="export_category"/>
                <br/>
				<input type="submit" value="Upload CSV" name="submit">
            </form>			
			<div class="import-fields">
				<button class="import-btn" onclick="import_data(event);" >Import Uploaded Files</button>	
			</div>
			<div id="msg-loading" style="display: none;" >
				uploading...
            </div>
			<div class="import-results">
			</div>
		</div>
		<?php
		echo ob_get_clean();	
    }

    public function get_taxonomy_hier( $taxon, $children = array() ) {
        if ( count( $children ) === 0 ) {
            $children[] = $taxon->name;
        }
        if ( $taxon->parent > 0 ) {
            $parent = get_term( $taxon->parent );
            $children[] = $parent->name;
            return $this->get_taxonomy_hier( $parent, $children );
        } else {
            return str_replace( "\"", "\"\"", implode( " | ", array_reverse( $children ) ) );
        }
    }

    public function get_taxon_string( $taxon ) {
        $hierarchy = $this->get_taxonomy_hier( $taxon );
        $parent_slug = "";
        if ( $taxon->parent > 0 ) {
            $parent_slug = get_term( $taxon->parent )->slug;
        }
        return "\"{$taxon->term_id}\",\"{$hierarchy}\",\"{$taxon->slug}\",\"{$parent_slug}\"\r\n";
    }

    public function get_taxon_child_strings( $taxon, $children = array() ) {
        foreach ( $taxon->children as $child ) {
            $hierarchy = $this->get_taxonomy_hier( $child );
            $parent_slug = "";
            if ( $child->parent > 0 ) {
                $parent_slug = get_term( $child->parent )->slug;
            }
            $children[] = "\"{$child->term_id}\",\"{$hierarchy}\",\"{$child->slug}\",\"{$parent_slug}\"\r\n";
            return $this->get_taxon_child_strings( $child, $children );
        }
        return implode("", array_unique( $children ) );
    }
    
    public function write( $file, $content, $append = true ) {
        file_put_contents( $file, $content, $append );
    }
    
    public function write_files( $files, $override = false ) {
        foreach ( $files as $path => $content ) {
            $this->create_directory_from_file_path( $path );
            $this->write( $path, $content, !$override );   
        }
    }
    
    public function import_data() {
        $upload_dir = wp_upload_dir();
        $cont_url = $upload_dir['basedir'];
        $folder = "default";
        if ( $_POST["import_products"] === "product" ) {
            $folder = "product";
        } else if ( $_POST["import_categories"] === "category" ) {
            $folder = "category";
        } else if ( $_POST["import_permalinks"] === "permalink" ) {
            $folder = "permalink";
        } else if ( $_POST["import_category_metadata"] === "category_metadata" ) {
            $folder = "category_export";
        }
        else if ( $_POST["export_categories"] === "export_category" ) {
            $folder = "category_metadata";
        }

        if ( strlen($_POST["extra_folder"]) > 0 ) {
            $extraFolder = $_POST["extra_folder"];
        } else {
            $extraFolder = "";
        }

        if ( $folder === "permalink" ) {

            session_start();

            $args = array( 'hide_empty' => false );

            $term_ids = get_terms( 'product_cat', $args );
            
            if ( isset( $_SESSION[ 'cat_skip_count' ] ) ) {
                $term_ids = array_slice( $term_ids, $_SESSION[ 'cat_skip_count' ] );
            }
 
            $settings = unserialize(
                get_option( 'permalinks_customizer_taxonomy_settings' )
            );
            $term_struct = '';
            $error       = 0;
            $generated   = 0;
            foreach ( $term_ids as $id ) {
                $new_permalink = '';
                $term          = get_term( $id );
                if ( '' == $term_struct ) {
                    if ( isset( $settings[$term->taxonomy . '_settings'] )
                    && isset( $settings[$term->taxonomy . '_settings']['structure'] )
                    && ! empty( $settings[$term->taxonomy . '_settings']['structure'] ) ) {
                        $term_struct = $settings[$term->taxonomy . '_settings']['structure'];
                    } else {
                        $error = 1;
                        break;
                    }
                }

                $new_permalink = $this->replace_term_tags( $term, $term_struct );

                if ( '' == $new_permalink ) {
                    continue;
                }

                $pc_frontend   = new Permalinks_Customizer_Frontend;
                $old_permalink = $pc_frontend->original_taxonomy_link( $id );

                if ( $new_permalink == $old_permalink ) {
                    continue;
                }

                $this->save_term_permalink(
                    $term, str_replace( '%2F', '/', urlencode( $new_permalink ) ),
                    $old_permalink, 1
                );
                $generated++;
                if ( !isset( $_SESSION[ 'cat_skip_count' ] ) ) {
                    $_SESSION[ 'cat_skip_count' ] = 1;
                }
                error_log( $_SESSION[ 'cat_skip_count' ] );
                $_SESSION[ 'cat_skip_count' ] = $_SESSION[ 'cat_skip_count' ] + 1;
            }
        } else if ( $folder === "category_export" ) {
            $taxonomies = $this->get_taxonomy_hierarchy_all('product_cat');
            $files = array();
            $file = $cont_url . "/exports-new/categories/category-export-" . date( "m-d-y-" ) . rand(1, 1000) . ".csv";
            $files[ $file ] = "\"id\",\"hierarchy\",\"slug\",\"parent-slug\"\r\n";
            foreach ( $taxonomies as $taxon ) {
                $files[ $file ] .= $this->get_taxon_string( $taxon );
                $files[ $file ] .= $this->get_taxon_child_strings( $taxon );
            }
            $files[ $file ] = implode( "\r\n", array_unique( explode( "\r\n", $files[ $file ] ) ) );
            $this->write_files( $files );            
        } else if ( $folder !== "default" ) {
            $dir = $cont_url . "/imports-new/{$folder}/queue/{$extraFolder}/{,*/,*/*/,*/*/*/,*/*/*/*/,*/*/*/*/*/}/*.csv";
            $files = glob($dir, GLOB_BRACE);
            global $wpdb;
            foreach ($files as $file) {
                $row = 1;
                $cols = "";
                if (($handle = fopen( $file, "r" ) ) !== FALSE) {
                    $headers = array();
                    while ( ( $data = fgetcsv( $handle, 100000000, ",") ) !== FALSE) {
                        if ( $row != 1 ) {
                            if ( count( $data ) > 0 ) {                      
                                if ( $folder === "category" ) {
                                    $categories = explode( " | ", $data[ array_search( "category_hierarchy", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ]);
                                    $counter = 0;
                                    $parent = 0;
                                    $total = count( $categories );
                                    foreach ( $categories as $category ) {
                                        $category = str_replace( "\"\"", "\"", $category );
                                        $taxonomies = $this->get_taxonomy_hierarchy('product_cat', $parent, $category );
                                        if ( count( $taxonomies ) === 0 ) {
                                            break;
                                        } else {
                                            $parent = $taxonomies[ array_keys( $taxonomies )[ 0 ] ]->term_id;
                                        }
                                        if ( $counter === ( $total - 4 ) ) {
                                            $desc = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category1description_", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $mkws = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category1metakeywords", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $md = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category1metadescription", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $pdfs = explode(",", $data[ array_search( "category1description__links", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ]);
                                            update_term_meta($parent, 'wpcf-description', $desc );
                                            update_term_meta($parent, 'wpcf-meta-keywords', $mkws );
                                            update_term_meta($parent, 'wpcf-meta-description', $md );
                                        } 
                                        if ( $counter === ( $total - 3 ) ) {
                                            $desc = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category2description_", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $mkws = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category2metakeywords", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $md = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category2metadescription", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            update_term_meta($parent, 'wpcf-description', $desc );
                                            update_term_meta($parent, 'wpcf-meta-keywords', $mkws );
                                            update_term_meta($parent, 'wpcf-meta-description', $md );
                                        }                                        
                                        if ( $counter === ( $total - 2 ) ) {
                                            $desc = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category3description_", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $mkws = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category3metakeywords", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $md = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category3metadescription", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            update_term_meta($parent, 'wpcf-description', $desc );
                                            update_term_meta($parent, 'wpcf-is-final-level', "1" );
                                            update_term_meta($parent, 'wpcf-meta-keywords', $mkws );
                                            update_term_meta($parent, 'wpcf-meta-description', $md );
                                        } 
                                        if ( $counter === ( $total - 1 ) ) {
                                            $desc = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "tabledescription_", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $cols = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "tablecolumns", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $notes = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "tablenotes_", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            update_term_meta($parent, 'wpcf-parts-table-description', $desc );
                                            update_term_meta($parent, 'wpcf-is-a-part-table', "1" );
                                            update_term_meta($parent, 'wpcf-part-table-columns', $cols );
                                            update_term_meta($parent, 'wpcf-parts-table-notes', $notes );
                                        }
                                        $counter++;
                                    }
                                } else if ( $folder = "category_metadata" ) { 
                                    $categories = explode( " | ", $data[ array_search( "category_hierarchy", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ]);
                                    $counter = 0;
                                    $parent = 0;
                                    $total = count( $categories );
                                    foreach ( $categories as $category ) {
                                        $category = str_replace( "\"\"", "\"", $category );
                                        $taxonomies = $this->get_taxonomy_hierarchy('product_cat', $parent, $category );
                                        if ( count( $taxonomies ) === 0 ) {
                                            break;
                                        } else {
                                            $parent = $taxonomies[ array_keys( $taxonomies )[ 0 ] ]->term_id;
                                        }
                                        if ( $counter === ( $total - 4 ) ) {
                                            $desc = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category1description_", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $mkws = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category1metakeywords", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $md = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category1metadescription", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $pdfs = explode(",", $data[ array_search( "category1description__links", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ]);
                                            update_term_meta($parent, 'wpcf-description', $desc );
                                            update_term_meta($parent, 'wpcf-meta-keywords', $mkws );
                                            update_term_meta($parent, 'wpcf-meta-description', $md );
                                        } 
                                        if ( $counter === ( $total - 3 ) ) {
                                            $desc = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category2description_", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $mkws = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category2metakeywords", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $md = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category2metadescription", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            update_term_meta($parent, 'wpcf-description', $desc );
                                            update_term_meta($parent, 'wpcf-meta-keywords', $mkws );
                                            update_term_meta($parent, 'wpcf-meta-description', $md );
                                        }                                        
                                        if ( $counter === ( $total - 2 ) ) {
                                            $desc = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category3description_", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $mkws = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category3metakeywords", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $md = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category3metadescription", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            update_term_meta($parent, 'wpcf-description', $desc );
                                            update_term_meta($parent, 'wpcf-is-final-level', "1" );
                                            update_term_meta($parent, 'wpcf-meta-keywords', $mkws );
                                            update_term_meta($parent, 'wpcf-meta-description', $md );
                                        } 
                                        if ( $counter === ( $total - 1 ) ) {
                                            $desc = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "tabledescription_", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $cols = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "tablecolumns", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $notes = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "tablenotes_", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ])));
                                            $imgs = explode(",", str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ array_search( "category3description__images", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ]))));
                                            update_term_meta($parent, 'wpcf-parts-table-description', $desc );
                                            update_term_meta($parent, 'wpcf-is-a-part-table', "1" );
                                            update_term_meta($parent, 'wpcf-part-table-columns', $cols );
                                            update_term_meta($parent, 'wpcf-parts-table-notes', $notes );
                                        }
                                        $counter++;
                                    }
                                } else if ( $folder === "product" ) {
                                    $sku = $data[ array_search( "catalognumber", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ];
                                    $sku = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $sku )));
                                    $categories = explode( " | ", $data[ array_search( "categories", array_map( function( $e ) { return strtolower( $e );  }, $headers) ) ]);
                                    $post = get_page_by_title( $sku, OBJECT, "product" );
                                    $post_id = 0;
                                    if ( $post === NULL ) {
                                        $post_data = array(
                                            'post_title' => $sku,
                                            'post_type' => 'product',
                                            'post_status'  => 'publish',
                                        );
                                        $post_id = wp_insert_post( $post_data );
                                    } else {
                                        $post_id = $post->ID;
                                    }
                                    if ( $post_id > 0 ) {
                                        for ( $i = 0; $i < count( $data ); $i++ ) {
                                            $data[ $i ] = str_replace("&#44;", ",", str_replace("&quot;", "\"", str_replace("\"\"", "\"",  $data[ $i ])));
                                            if ( strtolower( $headers[ $i ] ) === "catalognumber" || strtolower( $headers[ $i ] ) === "categories" || strtolower( $headers[ $i ] ) === "tablehasheading" ) {
                                                continue;
                                            }
                                            update_post_meta( $post_id, $headers[ $i ],  $data[ $i ] );
                                        }
                                        update_post_meta( $post_id, '_sku', $sku );
                                    }
                                    $term_ids = array();
                                    $parent = 0;
                                    $total = count( $categories );
                                    $counter = 0;
                                    foreach ( $categories as $category ) {
                                        $category = str_replace( "\"\"", "\"", $category );
                                        $taxonomies = $this->get_taxonomy_hierarchy('product_cat', $parent, $category );
                                        if ( count( $taxonomies ) === 0 ) {
                                            $args = array();
                                            if ( $parent !== 0 ) {
                                                $args = array( "parent" => $parent );
                                            }
                                            $term =  wp_insert_term( $category, 'product_cat', $args );
                                            $parent = is_array( $term ) ? $term['term_id'] : 0;
                                            $term_ids[] = $parent;
                                        } else {
                                            $parent = $taxonomies[ array_keys( $taxonomies )[ 0 ] ]->term_id;
                                            $term_ids[] = $parent;
                                        }
                                        if ( $counter === ( $total - 2 ) ) {
                                            update_term_meta($parent, 'wpcf-is-final-level', "1" );
                                        } 
                                        if ( $counter === ( $total - 1 ) ) {
                                            update_term_meta($parent, 'wpcf-is-a-part-table', "1" );
                                        }
                                        $counter++;
                                    }
                                    wp_set_post_terms( $post_id, $term_ids, 'product_cat' );
                                }
                            }
                        } else {
                            $headers = $data;
                        }
                        $row++;
                    }
                    fclose( $handle );
                }
                // $path = str_replace( "queue", "processed", $file );
                $path = str_replace( "queue", "processed", $file );
                $this->create_directory_from_file_path( $path );
                rename( $file, $path );
            }
        }
		echo "upload successful";
        die();
    }

    private function get_path_from_image( $image ) {
        return "/wp-content/uploads/product-assets/images/" . basename( str_replace("https://dev.newmacleanpower.com", "", $image ) );
    }

    private function save_term_permalink( $term, $permalink, $prev, $update ) {
        $url = get_term_meta( $term->term_id, 'permalink_customizer' );
        if ( empty( $url ) || 1 == $update ) {
          global $wpdb;
          $trailing_slash = substr( $permalink, -1 );
          if ( '/' == $trailing_slash ) {
            $permalink = rtrim( $permalink, '/' );
          }
          $set_permalink = $permalink;
          $qry = "SELECT * FROM $wpdb->termmeta WHERE meta_key = 'permalink_customizer' AND meta_value = '" . $permalink . "' AND term_id != " . $term->term_id . " OR meta_key = 'permalink_customizer' AND meta_value = '" . $permalink . "/' AND term_id != " . $term->term_id . " LIMIT 1";
          $check_exist_url = $wpdb->get_results( $qry );
          if ( ! empty( $check_exist_url ) ) {
            $i = 2;
            while (1) {
              $permalink = $set_permalink . '-' . $i;
              $qry = "SELECT * FROM $wpdb->termmeta WHERE meta_key = 'permalink_customizer' AND meta_value = '" . $permalink . "' AND term_id != " . $term->term_id . " OR meta_key = 'permalink_customizer' AND meta_value = '" . $permalink . "/' AND term_id != " . $term->term_id . " LIMIT 1";
              $check_exist_url = $wpdb->get_results( $qry );
              if ( empty( $check_exist_url ) ) break;
              $i++;
            }
          }
    
          if ( '/' == $trailing_slash ) {
            $permalink = $permalink . '/';
          }
    
          if ( strpos( $permalink, '/' ) === 0 ) {
            $permalink = substr( $permalink, 1 );
          }
        }
    
        update_term_meta( $term->term_id, 'permalink_customizer', $permalink );
    
        $taxonomy = 'category';
        if ( isset( $term->taxonomy ) && ! empty( $term->taxonomy ) ) {
          $taxonomy = $term->taxonomy;
        }
    
        if ( ! empty( $permalink ) && ! empty( $prev ) && $permalink != $prev  ) {
          $this->add_auto_redirect( $prev, $permalink, $taxonomy, $term->term_id );
        }
      }

      private function add_auto_redirect( $redirect_from, $redirect_to, $type, $id ) {
        $redirect_filter = apply_filters(
          'permalinks_customizer_auto_created_redirects', '__true'
        );
        if ( $redirect_from !== $redirect_to && '__true' === $redirect_filter ) {
          global $wpdb;
    
          $table_name = "{$wpdb->prefix}permalinks_customizer_redirects";
    
          $wpdb->query( $wpdb->prepare( "UPDATE $table_name SET enable = 0 " .
            " WHERE redirect_from = %s", $redirect_to
          ) );
    
          $post_perm = 'p=' . $id;
          $page_perm = 'page_id=' . $id;
          if ( 0 === strpos( $redirect_from, '?' ) ) {
            if ( false !== strpos( $redirect_from, $post_perm )
              || false !== strpos( $redirect_from, $page_perm ) ) {
              return;
            }
          }
    
          $find_red = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name " .
            " WHERE redirect_from = %s AND redirect_to = %s", $redirect_from,
            $redirect_to
          ) );
    
          if ( isset( $find_red ) && is_object( $find_red )
            && isset( $find_red->id ) ) {
    
            if ( isset( $find_red->enable ) && 0 == $find_red->enable ) {
              $wpdb->query( $wpdb->prepare( "UPDATE $table_name SET enable = 1 " .
                " WHERE id = %d", $find_red->id
              ) );
            }
          } else {
            $redirect_added = $wpdb->insert( $table_name, array(
              'redirect_from'   => $redirect_from,
              'redirect_to'     => $redirect_to,
              'type'            => $type,
              'redirect_status' => 'auto',
              'enable'          => 1,
            ));
          }
        }
      }

    private function replace_term_tags( $term, $replace_tag ) {

        if ( false !== strpos( $replace_tag, '%name%' ) ) {
          $name        = sanitize_title( $term->name );
          $replace_tag = str_replace( '%name%', $name, $replace_tag );
        }
    
        if ( false !== strpos( $replace_tag, '%term_id%' ) ) {
          $replace_tag = str_replace( '%term_id%', $term->term_id, $replace_tag );
        }
    
        if ( false !== strpos( $replace_tag, '%slug%' ) ) {
          if ( ! empty( $term->slug ) ) {
             $replace_tag = str_replace( '%slug%', $term->slug, $replace_tag );
          } else {
             $name        = sanitize_title( $term->name );
             $replace_tag = str_replace( '%slug%', $name, $replace_tag );
          }
        }
    
        if ( false !== strpos( $replace_tag, '%parent_slug%' ) ) {
          $parents    = get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' );
          $term_names = '';
          if ( $parents && ! empty( $parents ) && count( $parents ) >= 1 ) {
            $parent     = get_term( $parents[0] );
            $term_names = $parent->slug . '/';
          }
    
          if ( ! empty( $term->slug ) ) {
             $term_names .= $term->slug;
          } else {
             $title       = sanitize_title( $term->name );
             $term_names .=  $title;
          }
    
          $replace_tag = str_replace( '%parent_slug%', $term_names, $replace_tag );
        }
    
        if ( false !== strpos( $replace_tag, '%all_parents_slug%' ) ) {
          $parents    = get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' );
          $term_names = '';
          if ( $parents && ! empty( $parents ) && count( $parents ) >= 1 ) {
            $i = count( $parents ) - 1;
            for ( $i; $i >= 0; $i-- ) {
              $parent      = get_term( $parents[$i] );
              $term_names .= $parent->slug . '/';
            }
          }
    
          if ( ! empty( $term->slug ) ) {
             $term_names .= $term->slug;
          } else {
             $title       = sanitize_title( $term->name );
             $term_names .=  $title;
          }
    
          $replace_tag = str_replace( '%all_parents_slug%', $term_names, $replace_tag );
        }
    
        return $replace_tag;
      }

    /**
    * Recursively get taxonomy and its children
    *
    * @param string $taxonomy
    * @param int $parent - parent term id
    * @return array
    */
    public function get_taxonomy_hierarchy( $taxonomy, $parent = 0, $name = "" ) {
        // only 1 taxonomy
        $taxonomy = is_array( $taxonomy ) ? array_shift( $taxonomy ) : $taxonomy;
        // get all direct decendants of the $parent
        $args = array( 'parent' => $parent, 'hide_empty' => false, 'number' => 1 );

        if ( strlen( $name ) > 0 ) {
            $args[ "name" ] = $name;
        }

        $terms = get_terms( $taxonomy, $args );
        // prepare a new array.  these are the children of $parent
        // we'll ultimately copy all the $terms into this new array, but only after they
        // find their own children
        $children = array();
        // go through all the direct decendants of $parent, and gather their children
        foreach ( $terms as $term ){
            // recurse to get the direct decendants of "this" term
            $term->children = $this->get_taxonomy_hierarchy( $taxonomy, $term->term_id );
            // add the term to our new array
            $children[ $term->term_id ] = $term;
        }
        // send the results back to the caller
        return $children;
    }

    public function get_taxonomy_hierarchy_all( $taxonomy, $parent = 0, $name = "" ) {
        // only 1 taxonomy
        $taxonomy = is_array( $taxonomy ) ? array_shift( $taxonomy ) : $taxonomy;
        // get all direct decendants of the $parent
        $args = array( 'hide_empty' => false, 'number' => 0 );

        if ( strlen( $name ) > 0 ) {
            $args[ "name" ] = $name;
        }

        $terms = get_terms( $taxonomy, $args );
        // prepare a new array.  these are the children of $parent
        // we'll ultimately copy all the $terms into this new array, but only after they
        // find their own children
        $children = array();
        // go through all the direct decendants of $parent, and gather their children
        foreach ( $terms as $term ){
            // recurse to get the direct decendants of "this" term
            $term->children = $this->get_taxonomy_hierarchy( $taxonomy, $term->term_id );
            // add the term to our new array
            $children[ $term->term_id ] = $term;
        }
        // send the results back to the caller
        return $children;
    }
	
	
    public function admin_js_globals() {
        ?>
        <script>
            var wp_ajax_url = '<?php echo admin_url("admin-ajax.php"); ?>';
        </script>
        <?php
    }

    public function create_directory_from_file_path( $path ) {
        if ( !file_exists( trim( dirname( $path ) ) ) ) {
            mkdir( trim(dirname( $path )), 0777, true );
        }    
    }

    public function wp_get_attachment_by_post_name( $post_name ) {
            $args           = array(
                'posts_per_page' => 1,
                'post_type'      => 'attachment',
                'name'           => trim( $post_name ),
            );

            $get_attachment = new WP_Query( $args );

            if ( ! $get_attachment || ! isset( $get_attachment->posts, $get_attachment->posts[0] ) ) {
                return false;
            }

            return $get_attachment->posts[0];
    }

    public function crb_insert_attachment_from_url($url, $filename = "", $parent_post_id = null) {
        if( !class_exists( 'WP_Http' ) )
            include_once( ABSPATH . WPINC . '/class-http.php' );
        $http = new WP_Http();
        $response = $http->request( $url );
        if( $response['response']['code'] != 200 ) {
            return false;
        }
        $upload = wp_upload_bits( basename($url), null, $response['body'] );
        if( !empty( $upload['error'] ) ) {
            return false;
        }
        $file_path = $upload['file'];
        $file_name = basename( $file_path );
        $file_type = wp_check_filetype( $file_name, null );

        $attachment_title = $filename;
        if ( strlen( $filename ) === 0 ) {
            $attachment_title = sanitize_file_name( pathinfo( $file_name, PATHINFO_FILENAME ) );
        }

        $wp_upload_dir = wp_upload_dir();
        $post_info = array(
            'guid'           => $wp_upload_dir['url'] . '/' . $file_name,
            'post_mime_type' => $file_type['type'],
            'post_title'     => $attachment_title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        // Create the attachment
        $attach_id = wp_insert_attachment( $post_info, $file_path, $parent_post_id );
        // Include image.php
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        // Define attachment metadata
        $attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
        // Assign metadata to attachment
        wp_update_attachment_metadata( $attach_id,  $attach_data );
        return $attach_id;
    }

}
