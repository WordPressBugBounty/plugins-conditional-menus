<?php
/*
Plugin Name:  Conditional Menus
Plugin URI:   https://themify.me/conditional-menus
Version:      1.2.9
Author:       Themify
Author URI:   https://themify.me/
Description:  This plugin enables you to set conditional menus per posts, pages, categories, archive pages, etc.
Text Domain:  themify-cm
Domain Path:  /languages
License:      GNU General Public License v2.0
License URI:  http://www.gnu.org/licenses/gpl-2.0.html
*/

/*
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301, USA.
 *
 */

if ( !defined( 'ABSPATH' ) ) exit;

register_activation_hook( __FILE__, array( 'Themify_Conditional_Menus', 'activate' ) );

class Themify_Conditional_Menus {

	/** @var array|null Resolved menu locations for the current request */
	private $resolved_locations = null;

	/** @var array Memoized menu_id => WP_Term|false lookups for renderable menus */
	private $renderable_menus = array();

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'constants' ), 1 );
		add_action( 'plugins_loaded', array( $this, 'i18n' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'setup' ), 10 );
		add_action( 'wpml_after_startup', array( $this, 'wpml_after_startup' ) );
		add_filter( 'plugin_row_meta', array( $this, 'themify_plugin_meta'), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'action_links') );
	}

	public function constants() {
		if( ! defined( 'THEMIFY_CM_URI' ) ){
			define( 'THEMIFY_CM_URI', trailingslashit( plugin_dir_url( __FILE__ ) ) );
		}
	}

	public function themify_plugin_meta( $links, $file ) {
		if ( plugin_basename( __FILE__ ) === $file ) {
			$row_meta = array(
			  'changelogs'    => '<a href="' . esc_url( 'https://themify.org/changelogs/' ) . basename( dirname( $file ) ) .'.txt" target="_blank" aria-label="' . esc_attr__( 'Plugin Changelogs', 'themify-cm' ) . '">' . esc_html__( 'View Changelogs', 'themify-cm' ) . '</a>'
			);
	 
			return array_merge( $links, $row_meta );
		}
		return (array) $links;
	}
	public function action_links( $links ) {
		if ( is_plugin_active( 'themify-updater/themify-updater.php' ) ) {
			$tlinks = array(
			 '<a href="' . admin_url( 'index.php?page=themify-license' ) . '">'.__('Themify License', 'themify-cm') .'</a>',
			 );
		} else {
			$tlinks = array(
			 '<a href="' . esc_url('https://themify.me/docs/themify-updater-documentation') . '">'. __('Themify Updater', 'themify-cm') .'</a>',
			 );
		}
		return array_merge( $links, $tlinks );
	}
	public function i18n() {
		load_plugin_textdomain( 'themify-cm', false, '/languages' );
	}

	public function setup() {
		if ( is_admin() ) {
			add_action( 'load-nav-menus.php', array( $this, 'init' ) );
			add_action( 'wp_ajax_themify_cm_get_conditions', array( $this, 'ajax_get_conditions' ) );
			add_action( 'wp_ajax_themify_cm_create_inner_page', array( $this, 'ajax_create_inner_page' ) );
			add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
			add_action( 'admin_init', array( $this, 'activation_redirect' ) );
			add_action( 'wp_delete_nav_menu', array( $this, 'wp_delete_nav_menu' ) );
		} else {
			add_action( 'wp', array( $this, 'resolve_locations' ), 20 );
			/* Run after WPML (and anything else) that filters menu locations */
			add_filter( 'wp_nav_menu_args', array( $this, 'setup_menus' ), PHP_INT_MAX );
			add_filter( 'theme_mod_nav_menu_locations', array( $this, 'theme_mod_nav_menu_locations' ), PHP_INT_MAX );
		}
	}

	public function get_options() {
		remove_filter( 'theme_mod_nav_menu_locations', array( $this, 'theme_mod_nav_menu_locations' ), PHP_INT_MAX );
		$options = $this->get_conditional_menus_theme_mod();
		$options = wp_parse_args( $options, get_nav_menu_locations() );
		if( ! is_admin() ) {
			add_filter( 'theme_mod_nav_menu_locations', array( $this, 'theme_mod_nav_menu_locations' ), PHP_INT_MAX );
		}
		return $options;
	}

	/**
	 * Read conditional menu assignments from theme mods (raw DB, bypasses language filters).
	 *
	 * @return array
	 */
	private function get_conditional_menus_theme_mod() {
		foreach ( array_unique( array_filter( array( get_option( 'stylesheet' ), get_option( 'template' ) ) ) ) as $theme_slug ) {
			$mods = $this->get_raw_theme_mods( $theme_slug );
			if ( is_array( $mods ) && ! empty( $mods['themify_conditional_menus'] ) && is_array( $mods['themify_conditional_menus'] ) ) {
				return $mods['themify_conditional_menus'];
			}
		}
		$options = get_theme_mod( 'themify_conditional_menus', array() );
		return is_array( $options ) ? $options : array();
	}

	/**
	 * @param string $theme_slug
	 * @return array|null
	 */
	private function get_raw_theme_mods( $theme_slug ) {
		global $wpdb;
		if ( ! $theme_slug ) {
			return null;
		}
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			'theme_mods_' . $theme_slug
		) );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		$mods = maybe_unserialize( $raw );
		return is_array( $mods ) ? $mods : null;
	}

	/**
	 * Pre-resolve conditional menus once the main query is available.
	 */
	public function resolve_locations() {
		remove_filter( 'theme_mod_nav_menu_locations', array( $this, 'theme_mod_nav_menu_locations' ), PHP_INT_MAX );
		$locations = get_nav_menu_locations();
		add_filter( 'theme_mod_nav_menu_locations', array( $this, 'theme_mod_nav_menu_locations' ), PHP_INT_MAX );
		$this->resolved_locations = $this->apply_conditions_to_locations( is_array( $locations ) ? $locations : array() );
	}

	public function theme_mod_nav_menu_locations( $locations = array() ) {
		if ( is_array( $this->resolved_locations ) ) {
			return $this->resolved_locations;
		}
		if ( empty( $locations ) || ! did_action( 'wp' ) ) {
			return $locations;
		}
		return $this->apply_conditions_to_locations( $locations );
	}

	/**
	 * @param array $locations
	 * @return array
	 */
	private function apply_conditions_to_locations( $locations ) {
		$menu_assignments = $this->get_options();
		$hasLng = function_exists( 'pll_current_language' ) && function_exists( 'pll_default_language' );

		foreach ( $locations as $location => $menu_id ) {
			if ( empty( $menu_assignments[ $location ] ) || ! is_array( $menu_assignments[ $location ] ) ) {
				continue;
			}

			$menus = $menu_assignments[ $location ];

			if ( $hasLng && pll_current_language() !== pll_default_language() ) {
				$polylang_location = $location . '___' . pll_current_language();
				if ( ! empty( $menu_assignments[ $polylang_location ] ) && is_array( $menu_assignments[ $polylang_location ] ) ) {
					$menus = $menu_assignments[ $polylang_location ];
				}
			}

			foreach ( $menus as $new_menu ) {
				if ( ! is_array( $new_menu ) || ! isset( $new_menu['menu'], $new_menu['condition'] ) ) {
					continue;
				}
				if ( $new_menu['menu'] === '' || $new_menu['condition'] === '' ) {
					continue;
				}
				if ( ! $this->check_visibility( $new_menu['condition'] ) ) {
					continue;
				}
				if ( (string) $new_menu['menu'] === '0' ) {
					unset( $locations[ $location ] );
				} else {
					$locations[ $location ] = $this->translate_nav_menu_id( $new_menu['menu'] );
				}
			}
		}

		return $locations;
	}

	/**
	 * Where magic happens.
	 * Filters wp_nav_menu_args to dynamically swap parameters sent to it to change what menu displayed.
	 *
	 * @return array
	 */
	public function setup_menus( $args ) {
		$menu_assignments = $this->get_options();
		$theme_location = ! empty( $args['theme_location'] ) ? $args['theme_location'] : '';
		if ( $theme_location === '' || empty( $menu_assignments[ $theme_location ] ) || ! is_array( $menu_assignments[ $theme_location ] ) ) {
			return $args;
		}

		/* Allow overriding even when a menu ID is already set (e.g. by WPML) */
		foreach ( $menu_assignments[ $theme_location ] as $new_menu ) {
			if ( ! is_array( $new_menu ) || ! isset( $new_menu['menu'], $new_menu['condition'] ) ) {
				continue;
			}
			if ( $new_menu['menu'] === '' || $new_menu['condition'] === '' ) {
				continue;
			}
			if ( ! $this->check_visibility( $new_menu['condition'] ) ) {
				continue;
			}
			if ( (string) $new_menu['menu'] === '0' ) {
				add_filter( 'pre_wp_nav_menu', array( $this, 'disable_menu' ), 10, 2 );
				$args['echo'] = false;
			} else {
				$menu = $this->get_renderable_nav_menu( $this->translate_nav_menu_id( $new_menu['menu'] ) );
				if ( ! $menu ) {
					/* Leave $args alone so wp_nav_menu() keeps resolving via theme_location */
					continue;
				}
				/* Pass the term object: wp_nav_menu() only swaps $args['menu'] for the
				   object when it is empty, and walkers expect $args->menu to be one. */
				$args['menu'] = $menu;
				$location = apply_filters( 'conditional_menus_theme_location', '', $new_menu, $args );
				if ( $location !== '' ) {
					$args['theme_location'] = $location;
				}
			}
		}

		return $args;
	}

	/**
	 * Resolve a menu assignment to a menu that wp_nav_menu() can actually render.
	 *
	 * @param int $menu_id
	 * @return WP_Term|false
	 */
	private function get_renderable_nav_menu( $menu_id ) {
		$menu_id = (int) $menu_id;
		if ( $menu_id <= 0 ) {
			return false;
		}
		if ( ! isset( $this->renderable_menus[ $menu_id ] ) ) {
			$menu = wp_get_nav_menu_object( $menu_id );
			$items = ( $menu && ! is_wp_error( $menu ) )
				? wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) )
				: false;
			$this->renderable_menus[ $menu_id ] = empty( $items ) ? false : $menu;
		}
		return $this->renderable_menus[ $menu_id ];
	}

	public function disable_menu( $output, $args ) {
		remove_filter( 'pre_wp_nav_menu', array( $this, 'disable_menu' ), 10, 2 );
		return '';
	}

	public function init() {
		if( ( isset( $_GET['action'] ) && 'locations' === $_GET['action'] ) || isset( $_POST['menu-locations'] ) ) {
			$this->save_options();
			add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue' ) );
			add_action( 'admin_footer', array( $this, 'output_nonce' ) );
		}
	}

	public function save_options() {
		if( isset( $_POST['menu-locations'] ) ) {
			if ( ! isset( $_POST['themify_cm_nonce'] ) || ! wp_verify_nonce( $_POST['themify_cm_nonce'], 'themify_cm_nonce' ) ) {
				return;
			}
		    $themify_cm = isset( $_POST['themify_cm'] ) ? $_POST['themify_cm'] : array();
		    set_theme_mod( 'themify_conditional_menus', $themify_cm );
		}
	}

	public function ajax_get_conditions() {
		check_ajax_referer( 'themify_cm_nonce', 'nonce' );
		include trailingslashit( plugin_dir_path( __FILE__ ) ) . 'templates/conditions.php';
		die;
	}

	public function output_nonce() {
		wp_nonce_field( 'themify_cm_nonce', 'themify_cm_nonce' );
	}

	public function admin_enqueue() {
		global $_wp_registered_nav_menus;
		$version='1.2.9';
		self::themify_enque_style( 'themify-conditional-menus', THEMIFY_CM_URI . 'assets/admin.css', null, $version );
		wp_enqueue_script( 'themify-conditional-menus', self::themify_enque(THEMIFY_CM_URI . 'assets/admin.js'), array( 'jquery', 'jquery-ui-tabs' ), $version, true );
		wp_localize_script( 'themify-conditional-menus', 'themify_cm', array(
			'nonce' => wp_create_nonce( 'themify_cm_nonce' ),
			'nav_menus' => array_keys( $_wp_registered_nav_menus ),
			'options' => $this->get_options(),
			'lang' => array(
				'conditions' => __( '+ Conditions', 'themify-cm' ),
				'add_assignment' => __( '+ Conditional Menu', 'themify-cm' ),
				'disable_menu' => __( 'Disable Menu', 'themify-cm' ),
			),
		) );
	}

	/**
	 * Check if an item is visible for the current context
	 *
	 * @return bool
	 */
	public function check_visibility( $logic ) {
		parse_str( $logic, $logic );
		$query_object = get_queried_object();
		/* WPML: add translated page/term slugs to the condition so core checks succeed */
		$this->expand_wpml_post_type_conditions( $logic );
		$this->expand_wpml_tax_conditions( $logic );

		// Logged-in check
		if( isset( $logic['general']['logged'] ) ) {
			if( ! is_user_logged_in() ) {
				return false;
			}
			unset( $logic['general']['logged'] );
			if( empty( $logic['general'] ) ) {
			    unset( $logic['general'] );
			}
		}

		// User role check
		if ( ! empty( $logic['roles'] )
			// check if *any* of user's role(s) matches
			&& ! count( array_intersect( wp_get_current_user()->roles, array_keys( $logic['roles'], true ) ) )
		) {
			return false; // bail early.
		}
		unset( $logic['roles'] );

		if( ! empty( $logic ) ) {
			if( ( isset( $logic['general']['home'] ) && is_front_page())
				|| (isset( $logic['general']['404'] ) &&  is_404() )
				|| (isset( $logic['general']['page'] ) &&  is_page() &&  ! is_front_page() )
				|| (isset( $logic['general']['single'] ) && is_single() )
				|| ( isset( $logic['general']['search'] )  && is_search() )
				|| ( isset( $logic['general']['author'] ) && is_author() )
				|| ( isset( $logic['general']['category'] ) && is_category())
				|| ( isset($logic['general']['tag']) && is_tag() )
				|| ( isset($logic['general']['date']) && is_date() )
				|| ( isset($logic['general']['year'])  && is_year())
				|| ( isset($logic['general']['month']) && is_month())
				|| (isset($logic['general']['day']) && is_day())
				|| ( is_singular() && is_object( $query_object ) && isset( $logic['general'][$query_object->post_type] ) && $query_object->post_type !== 'page' && $query_object->post_type !== 'post' )
				|| ( is_tax() && is_object( $query_object ) && isset( $logic['general'][$query_object->taxonomy] ) )
				|| ( is_post_type_archive() && is_object( $query_object ) && isset( $logic['general'][ $query_object->name . '_archive' ] ) )
			) {
				return true;
			} else { // let's dig deeper into more specific visibility rules
				if( ! empty( $logic['tax'] ) ) {
					if(is_singular()){
						if( !empty($logic['tax']['category_single'])){
							if ( empty( $logic['tax']['category_single']['category'] ) ) {
								$cat = get_the_category();
								if(!empty($cat)){
									foreach($cat as $c){
										if($c->taxonomy === 'category' && isset($logic['tax']['category_single']['category'][$c->slug])){
											return true;
										}
									}
								}
								unset($logic['tax']['category_single']['category']);
							}
							foreach ($logic['tax']['category_single'] as $key => $tax) {
								$terms = get_the_terms( get_the_ID(), $key);
								if ( $terms !== false && !is_wp_error($terms) && is_array($terms) ) {
									foreach ( $terms as $term ) {
										if( isset($logic['tax']['category_single'][$key][$term->slug]) ){
											return true;
										}
										if ( $this->wpml_term_slug_in_condition( $term, $key, $logic['tax']['category_single'][$key] ) ) {
											return true;
										}
									}
								}
							}
						}
					} else {
						foreach( $logic['tax'] as $tax => $terms ) {
							if ( $tax === 'category_single' || ! is_array( $terms ) ) {
								continue;
							}
							$terms = array_keys( $terms );
							self::update_non_ascii_slugs( $terms );
							if( ( $tax === 'category' && is_category( $terms ) )
								|| ( $tax === 'post_tag' && is_tag( $terms ) )
								|| ( is_tax( $tax, $terms ) )
							) {
								return true;
							}
							if ( $this->wpml_queried_term_matches( $tax, $terms ) ) {
								return true;
							}
						}
					}
				}

				if ( ! empty( $logic['post_type'] ) ) {
					foreach( $logic['post_type'] as $post_type => $posts ) {
						$posts = array_keys( $posts );
						self::update_non_ascii_slugs( $posts );

						if (
							( $post_type === 'post' && is_single( $posts ) )
							|| ( $post_type === 'page' && (
								( 
									( is_page( $posts )
									|| ( is_object( $query_object ) && isset( $query_object->post_parent ) && $query_object->post_parent > 0 &&
									     ( in_array( '/' . str_replace( strtok( get_home_url(), '?'), '', remove_query_arg( 'lang', get_permalink( $query_object->ID ) ) ), $posts ) ||
									     in_array( str_replace( strtok( get_home_url(), '?'), '', remove_query_arg( 'lang', get_permalink( $query_object->ID ) ) ), $posts ) ||
									     in_array( '/'.$this->child_post_name($query_object).'/', $posts ) )
									  )
								) )
								|| ( ! is_front_page() && is_home() &&  in_array( get_post_field( 'post_name', get_option( 'page_for_posts' ) ), $posts,true ) )
								|| ( class_exists( 'WooCommerce' ) && function_exists( 'is_shop' ) && is_shop() && in_array( get_post_field( 'post_name', wc_get_page_id( 'shop' ) ), $posts )  )
							) )
							|| ( is_singular( $post_type ) && is_object( $query_object ) && in_array( $query_object->post_name, $posts,true ) )
							|| ( is_singular( $post_type ) && is_object( $query_object ) && isset( $query_object->post_parent ) && $query_object->post_parent > 0 && in_array( '/'.$this->child_post_name($query_object).'/', $posts,true ) )
							|| ( is_singular( $post_type ) && get_post_type() === $post_type && in_array( 'E_ALL', $posts ) )
							|| ( is_singular( $post_type ) && $query_object instanceof WP_Post && $this->wpml_current_post_matches_slugs( $query_object, $posts ) )
						) {
							return true;
						}
					}
				}
			}
			return false;
		}

		return true;
	}

	/**
	 * Render pagination for specific page.
	 *
	 * @param Integer $current_page The current page that needs to be rendered.
	 * @param Integer $num_of_pages The number of all pages.
	 *
	 * @return String The HTML with pagination.
	 */
	function create_page_pagination( $current_page, $num_of_pages ) {
		$links_in_the_middle = 4;
		$links_in_the_middle_min_1 = $links_in_the_middle - 1;
		$first_link_in_the_middle   = $current_page - floor( $links_in_the_middle_min_1 / 2 );
		$last_link_in_the_middle    = $current_page + ceil( $links_in_the_middle_min_1 / 2 );
		if ( $first_link_in_the_middle <= 0 ) {
			$first_link_in_the_middle = 1;
		}
		if ( ( $last_link_in_the_middle - $first_link_in_the_middle ) != $links_in_the_middle_min_1 ) {
			$last_link_in_the_middle = $first_link_in_the_middle + $links_in_the_middle_min_1;
		}
		if ( $last_link_in_the_middle > $num_of_pages ) {
			$first_link_in_the_middle = $num_of_pages - $links_in_the_middle_min_1;
			$last_link_in_the_middle  = (int) $num_of_pages;
		}
		if ( $first_link_in_the_middle <= 0 ) {
			$first_link_in_the_middle = 1;
		}
		$pagination = '';
		if ( $current_page != 1 ) {
			$pagination .= '<a href="' . ( $current_page - 1 ) . '" class="prev page-numbers ti-angle-left"/>';
		}
		if ( $first_link_in_the_middle >= 3 && $links_in_the_middle < $num_of_pages ) {
			$pagination .= '<a href="1" class="page-numbers">1</a>';

			if ( $first_link_in_the_middle != 2 ) {
				$pagination .= '<span class="page-numbers extend">...</span>';
			}
		}
		for ( $i = $first_link_in_the_middle; $i <= $last_link_in_the_middle; $i ++ ) {
			if ( $i == $current_page ) {
				$pagination .= '<span class="page-numbers current">' . $i . '</span>';
			} else {
				$pagination .= '<a href="' . $i . '" class="page-numbers">' . $i . '</a>';
			}
		}
		if ( $last_link_in_the_middle < $num_of_pages ) {
			if ( $last_link_in_the_middle != ( $num_of_pages - 1 ) ) {
				$pagination .= '<span class="page-numbers extend">...</span>';
			}
			$pagination .= '<a href="' . $num_of_pages . '" class="page-numbers">' . $num_of_pages . '</a>';
		}
		if ( $current_page != $last_link_in_the_middle ) {
			$pagination .= '<a href="' . ( $current_page + $i ) . '" class="next page-numbers ti-angle-right"></a>';
		}

		return $pagination;
	}

	function ajax_create_inner_page() {
		check_ajax_referer( 'themify_cm_nonce', 'nonce' );
		if ( empty( $_POST['type'] ) ) {
			die;
		}
		$type = explode( ':', $_POST['type'] );
		$paged = isset( $_POST['paged'] ) ? (int) $_POST['paged'] : 1;
		echo $this->create_inner_page( $type[0], $type[1], $paged );
		die;
	}

	/**
	 * Renders pages, posts types and categories items based on current page.
	 *
	 * @param string $type The type of items to render.
	 *
	 * @return array The HTML to render items as HTML and original values.
	 */
	function create_inner_page( $item_type, $type, $paged = 1 ) {
		$posts_per_page = 26;
		$output = '';
		if ( 'post_type' === $item_type ) {
			$query = new WP_Query( array( 'post_type' => $type, 'posts_per_page' => $posts_per_page, 'post_status' => 'publish', 'order' => 'ASC', 'orderby' => 'title', 'paged' => $paged ) );
			if ( $query->have_posts() ) {
				$num_of_single_pages = $query->found_posts;
				$num_of_pages        = (int) ceil( $num_of_single_pages / $posts_per_page );
				$output .= '<div class="themify-visibility-items-page themify-visibility-items-page-' . $paged . '">';
				foreach ( $query->posts as $post ) :
					$post->post_name = $this->child_post_name($post);
					if ( $post->post_parent > 0 ) {
						$post->post_name = '/' . $post->post_name . '/';
					}
					/* note: slugs are more reliable than IDs, they stay unique after export/import */
					$output .= '<label><input type="checkbox" name="' . esc_attr( 'post_type[' . $type . '][' . $post->post_name . ']' ) . '" /><span data-tooltip="'.get_permalink($post->ID).'">' . esc_html( $post->post_title ) . '</span></label>';
				endforeach;

				if ( $num_of_pages > 1 ) {
					$output .= '<div class="themify-visibility-pagination">';
					$output .= $this->create_page_pagination( $paged, $num_of_pages );
					$output .= '</div>';
				}
				$output .= '</div><!-- .themify-visibility-items-page -->';
			}
		} else if ( 'tax' === $item_type || 'in_tax' === $item_type ) {
			$total = wp_count_terms( [ 'taxonomy' => $type, 'hide_empty' => false ] );
			if ( ! is_wp_error( $total ) && ! empty( $total ) ) {
				$prefix = 'tax' === $item_type ? "tax[{$type}]" : "tax[category_single][{$type}]";
				$terms = get_terms( array( 'taxonomy' => $type, 'hide_empty' => false, 'number' => $posts_per_page, 'offset' => ( $paged - 1 ) * $posts_per_page ) );
				$num_of_pages = (int) ceil( $total / $posts_per_page );
				$output .= '<div class="themify-visibility-items-page themify-visibility-items-page-' . $paged . '">';
				foreach ( $terms as $term ) :
					$data = ' data-slug="'.$term->slug.'"';
					if ( $term->parent != '0' ) {
						$parent  = get_term( $term->parent, $type );
						$data .= ' data-parent="'.$parent->slug.'"';
					}
					$output  .= '<label><input'.$data.' type="checkbox" name="' . $prefix . '[' . $term->slug . ']" /><span data-tooltip="'.get_term_link($term).'">' . $term->name . '</span></label>';
				endforeach;
				if ( $num_of_pages > 1 ) {
					$output .= '<div class="themify-visibility-pagination">';
					$output .= $this->create_page_pagination( $paged, $num_of_pages );
					$output .= '</div>';
				}
				$output .= '</div><!-- .themify-visibility-items-page -->';
			}
		}

		return $output;
	}

	private function child_post_name($post) {
		$str = $post->post_name;

		if ( $post->post_parent > 0 ) {
			$parent = get_post($post->post_parent);
			if ( $parent ) {
				$parent->post_name = $this->child_post_name($parent);
				$str = $parent->post_name . '/' . $str;
			}
		}

		return $str;
	}

	/**
	 * Whether WPML is available for object ID translation.
	 *
	 * @return bool
	 */
	private function is_wpml_active() {
		return defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_object_id' );
	}

	/**
	 * Expand saved post_type condition slugs with their WPML translations (Builder Pro approach).
	 * Conditions are saved against default-language slugs; this adds current-language slugs so
	 * is_page() / post_name checks succeed on translated pages.
	 *
	 * @param array $logic
	 */
	private function expand_wpml_post_type_conditions( &$logic ) {
		if ( empty( $logic['post_type'] ) || ! is_array( $logic['post_type'] ) || ! $this->is_wpml_active() ) {
			return;
		}

		$default_lang = apply_filters( 'wpml_default_language', null );
		$current_lang = apply_filters( 'wpml_current_language', null );
		if ( ! is_string( $default_lang ) || $default_lang === '' || $default_lang === $current_lang ) {
			return;
		}

		foreach ( $logic['post_type'] as $post_type => $posts ) {
			if ( ! is_array( $posts ) ) {
				continue;
			}
			$extra = array();
			foreach ( array_keys( $posts ) as $slug ) {
				if ( ! is_string( $slug ) || $slug === '' || $slug === 'E_ALL' ) {
					continue;
				}
				$path = trim( $slug, '/' );
				if ( $path === '' ) {
					continue;
				}

				$source_id = $this->find_post_id_by_path_unfiltered( $path, $post_type, $default_lang );
				if ( $source_id < 1 ) {
					continue;
				}

				$translated_id = (int) apply_filters( 'wpml_object_id', $source_id, $post_type, false, $current_lang );
				if ( $translated_id < 1 || $translated_id === $source_id ) {
					continue;
				}

				$translated_slug = $this->get_post_name_raw( $translated_id );
				if ( $translated_slug !== '' ) {
					$extra[ $translated_slug ] = 'on';
				}

				$parent = (int) $this->get_post_field_raw( $translated_id, 'post_parent' );
				if ( $parent > 0 ) {
					$path_slug = $this->get_post_path_raw( $translated_id );
					if ( $path_slug !== '' ) {
						$extra[ '/' . $path_slug . '/' ] = 'on';
					}
				}
			}
			if ( ! empty( $extra ) ) {
				$logic['post_type'][ $post_type ] = array_merge( $posts, $extra );
			}
		}
	}

	/**
	 * Expand saved taxonomy condition slugs with their WPML translations.
	 * e.g. tax[category][uncategorized] also matches non-classifiee on French archives.
	 *
	 * @param array $logic
	 */
	private function expand_wpml_tax_conditions( &$logic ) {
		if ( empty( $logic['tax'] ) || ! is_array( $logic['tax'] ) || ! $this->is_wpml_active() ) {
			return;
		}

		$default_lang = apply_filters( 'wpml_default_language', null );
		$current_lang = apply_filters( 'wpml_current_language', null );
		if ( ! is_string( $default_lang ) || $default_lang === '' || $default_lang === $current_lang ) {
			return;
		}

		foreach ( $logic['tax'] as $taxonomy => $terms ) {
			if ( $taxonomy === 'category_single' ) {
				if ( ! is_array( $terms ) ) {
					continue;
				}
				foreach ( $terms as $tax_name => $tax_terms ) {
					if ( ! is_array( $tax_terms ) ) {
						continue;
					}
					$extra = $this->get_wpml_translated_term_slugs( $tax_name, array_keys( $tax_terms ), $default_lang, $current_lang );
					if ( ! empty( $extra ) ) {
						$logic['tax']['category_single'][ $tax_name ] = array_merge( $tax_terms, $extra );
					}
				}
				continue;
			}

			if ( ! is_array( $terms ) ) {
				continue;
			}
			$extra = $this->get_wpml_translated_term_slugs( $taxonomy, array_keys( $terms ), $default_lang, $current_lang );
			if ( ! empty( $extra ) ) {
				$logic['tax'][ $taxonomy ] = array_merge( $terms, $extra );
			}
		}
	}

	/**
	 * Map default-language term slugs to current-language slug => on entries.
	 *
	 * @param string $taxonomy
	 * @param array  $slugs
	 * @param string $default_lang
	 * @param string $current_lang
	 * @return array
	 */
	private function get_wpml_translated_term_slugs( $taxonomy, $slugs, $default_lang, $current_lang ) {
		$extra = array();
		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || $slug === '' ) {
				continue;
			}
			$source_id = $this->find_term_id_by_slug_unfiltered( $slug, $taxonomy, $default_lang );
			if ( $source_id < 1 ) {
				continue;
			}
			$translated_id = (int) apply_filters( 'wpml_object_id', $source_id, $taxonomy, false, $current_lang );
			if ( $translated_id < 1 || $translated_id === $source_id ) {
				continue;
			}
			$translated_slug = $this->get_term_slug_raw( $translated_id );
			if ( $translated_slug !== '' ) {
				$extra[ $translated_slug ] = 'on';
			}
		}
		return $extra;
	}

	/**
	 * Whether the current post matches condition slugs via WPML translation group.
	 *
	 * @param WP_Post $post
	 * @param array   $slugs
	 * @return bool
	 */
	private function wpml_current_post_matches_slugs( $post, $slugs ) {
		if ( ! $this->is_wpml_active() || ! ( $post instanceof WP_Post ) || empty( $slugs ) ) {
			return false;
		}

		$current_slug = $this->get_post_name_raw( (int) $post->ID );
		if ( $current_slug !== '' && in_array( $current_slug, $slugs, true ) ) {
			return true;
		}

		$default_lang = apply_filters( 'wpml_default_language', null );
		$current_lang = apply_filters( 'wpml_current_language', null );
		if ( ! is_string( $default_lang ) || $default_lang === '' ) {
			return false;
		}

		$original_id = (int) apply_filters( 'wpml_object_id', (int) $post->ID, $post->post_type, true, $default_lang );
		if ( $original_id > 0 ) {
			$original_slug = $this->get_post_name_raw( $original_id );
			if ( $original_slug !== '' && in_array( $original_slug, $slugs, true ) ) {
				return true;
			}
			$original_path = $this->get_post_path_raw( $original_id );
			if ( $original_path !== '' && in_array( '/' . $original_path . '/', $slugs, true ) ) {
				return true;
			}
		}

		if ( ! is_string( $current_lang ) || $current_lang === '' || $current_lang === $default_lang ) {
			return false;
		}

		$element_type = 'post_' . $post->post_type;
		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || $slug === '' || $slug === 'E_ALL' ) {
				continue;
			}
			$path = trim( $slug, '/' );
			if ( $path === '' ) {
				continue;
			}

			$source_id = $this->find_post_id_by_path_unfiltered( $path, $post->post_type, $default_lang );
			if ( $source_id < 1 ) {
				continue;
			}

			$translated_id = (int) apply_filters( 'wpml_object_id', $source_id, $post->post_type, false, $current_lang );
			if ( $translated_id > 0 && $translated_id === (int) $post->ID ) {
				return true;
			}

			$source_trid = apply_filters( 'wpml_element_trid', null, $source_id, $element_type );
			$current_trid = apply_filters( 'wpml_element_trid', null, (int) $post->ID, $element_type );
			if ( $source_trid && $current_trid && (int) $source_trid === (int) $current_trid ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param int    $post_id
	 * @param string $field
	 * @return string
	 */
	private function get_post_field_raw( $post_id, $field ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$allowed = array( 'post_name', 'post_parent', 'post_type', 'post_status' );
		if ( $post_id < 1 || ! in_array( $field, $allowed, true ) ) {
			return '';
		}
		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT {$field} FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
			$post_id
		) );
		return is_string( $value ) || is_numeric( $value ) ? (string) $value : '';
	}

	/**
	 * @param int $post_id
	 * @return string
	 */
	private function get_post_name_raw( $post_id ) {
		return $this->get_post_field_raw( $post_id, 'post_name' );
	}

	/**
	 * Build parent/child path from DB (unfiltered).
	 *
	 * @param int $post_id
	 * @return string
	 */
	private function get_post_path_raw( $post_id ) {
		$parts = array();
		$id = (int) $post_id;
		$guard = 0;
		while ( $id > 0 && $guard < 20 ) {
			$name = $this->get_post_name_raw( $id );
			if ( $name === '' ) {
				break;
			}
			array_unshift( $parts, $name );
			$id = (int) $this->get_post_field_raw( $id, 'post_parent' );
			$guard++;
		}
		return implode( '/', $parts );
	}

	/**
	 * Find a post ID by slug/path without WPML language filtering.
	 *
	 * @param string $path
	 * @param string $post_type
	 * @param string $lang
	 * @return int
	 */
	private function find_post_id_by_path_unfiltered( $path, $post_type, $lang ) {
		global $wpdb;
		$path = trim( (string) $path, '/' );
		if ( $path === '' ) {
			return 0;
		}

		$parts = explode( '/', $path );
		$parent = 0;
		$id = 0;
		foreach ( $parts as $part ) {
			$candidates = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND post_parent = %d AND post_status = 'publish' ORDER BY ID ASC",
				$part,
				$post_type,
				$parent
			) );
			if ( empty( $candidates ) ) {
				return 0;
			}

			$id = 0;
			foreach ( $candidates as $candidate_id ) {
				$candidate_id = (int) $candidate_id;
				$in_lang = (int) apply_filters( 'wpml_object_id', $candidate_id, $post_type, false, $lang );
				if ( $in_lang === $candidate_id ) {
					$id = $candidate_id;
					break;
				}
			}
			if ( $id < 1 ) {
				/* Fall back to first candidate mapped into $lang */
				$id = (int) apply_filters( 'wpml_object_id', (int) $candidates[0], $post_type, true, $lang );
			}
			if ( $id < 1 ) {
				return 0;
			}
			$parent = $id;
		}

		return $id;
	}

	/**
	 * Translate a nav menu ID to the current WPML language.
	 * Falls back to the original when the translation is missing or has no items.
	 *
	 * @param int $menu_id
	 * @return int
	 */
	private function translate_nav_menu_id( $menu_id ) {
		$menu_id = (int) $menu_id;
		if ( $menu_id <= 0 || ! $this->is_wpml_active() ) {
			return $menu_id;
		}

		$current_lang = apply_filters( 'wpml_current_language', null );
		$translated = (int) apply_filters( 'wpml_object_id', $menu_id, 'nav_menu', false, $current_lang );
		if ( $translated <= 0 || $translated === $menu_id ) {
			return $menu_id;
		}

		$items = wp_get_nav_menu_items( $translated );
		return ! empty( $items ) ? $translated : $menu_id;
	}

	/**
	 * Whether a WPML-translated term's default-language slug is in a condition map.
	 *
	 * @param WP_Term $term
	 * @param string  $taxonomy
	 * @param array   $condition_terms slug => on
	 * @return bool
	 */
	private function wpml_term_slug_in_condition( $term, $taxonomy, $condition_terms ) {
		if ( ! $this->is_wpml_active() || ! ( $term instanceof WP_Term ) || empty( $condition_terms ) ) {
			return false;
		}

		$default_lang = apply_filters( 'wpml_default_language', null );
		$current_lang = apply_filters( 'wpml_current_language', null );
		if ( ! is_string( $default_lang ) || $default_lang === '' || $default_lang === $current_lang ) {
			return false;
		}

		$original_id = (int) apply_filters( 'wpml_object_id', (int) $term->term_id, $taxonomy, false, $default_lang );
		if ( $original_id <= 0 ) {
			return false;
		}

		$original_slug = $this->get_term_slug_raw( $original_id );
		return ( $original_slug !== '' && isset( $condition_terms[ $original_slug ] ) );
	}

	/**
	 * Whether the queried taxonomy term matches condition slugs via its WPML original.
	 *
	 * @param string $taxonomy
	 * @param array  $slugs
	 * @return bool
	 */
	private function wpml_queried_term_matches( $taxonomy, $slugs ) {
		if ( ! $this->is_wpml_active() || empty( $slugs ) ) {
			return false;
		}
		if ( ! ( ( $taxonomy === 'category' && is_category() ) || ( $taxonomy === 'post_tag' && is_tag() ) || is_tax( $taxonomy ) ) ) {
			return false;
		}

		$queried = get_queried_object();
		if ( ! ( $queried instanceof WP_Term ) || $queried->taxonomy !== $taxonomy ) {
			return false;
		}

		/* Current-language slug may already be in the expanded list */
		$current_slug = $this->get_term_slug_raw( (int) $queried->term_id );
		if ( $current_slug !== '' && in_array( $current_slug, $slugs, true ) ) {
			return true;
		}

		$default_lang = apply_filters( 'wpml_default_language', null );
		$current_lang = apply_filters( 'wpml_current_language', null );
		if ( ! is_string( $default_lang ) || $default_lang === '' || $default_lang === $current_lang ) {
			return false;
		}

		$original_id = (int) apply_filters( 'wpml_object_id', (int) $queried->term_id, $taxonomy, true, $default_lang );
		if ( $original_id > 0 ) {
			$original_slug = $this->get_term_slug_raw( $original_id );
			if ( $original_slug !== '' && in_array( $original_slug, $slugs, true ) ) {
				return true;
			}
		}

		$element_type = 'tax_' . $taxonomy;
		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || $slug === '' ) {
				continue;
			}
			$source_id = $this->find_term_id_by_slug_unfiltered( $slug, $taxonomy, $default_lang );
			if ( $source_id < 1 ) {
				continue;
			}
			$translated_id = (int) apply_filters( 'wpml_object_id', $source_id, $taxonomy, false, $current_lang );
			if ( $translated_id > 0 && $translated_id === (int) $queried->term_id ) {
				return true;
			}
			$source_tt_id = $this->get_term_taxonomy_id_raw( $source_id, $taxonomy );
			$current_tt_id = isset( $queried->term_taxonomy_id ) ? (int) $queried->term_taxonomy_id : $this->get_term_taxonomy_id_raw( (int) $queried->term_id, $taxonomy );
			if ( $source_tt_id > 0 && $current_tt_id > 0 ) {
				$source_trid = apply_filters( 'wpml_element_trid', null, $source_tt_id, $element_type );
				$current_trid = apply_filters( 'wpml_element_trid', null, $current_tt_id, $element_type );
				if ( $source_trid && $current_trid && (int) $source_trid === (int) $current_trid ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param int $term_id
	 * @return string
	 */
	private function get_term_slug_raw( $term_id ) {
		global $wpdb;
		$term_id = (int) $term_id;
		if ( $term_id < 1 ) {
			return '';
		}
		$slug = $wpdb->get_var( $wpdb->prepare(
			"SELECT slug FROM {$wpdb->terms} WHERE term_id = %d LIMIT 1",
			$term_id
		) );
		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @return int
	 */
	private function get_term_taxonomy_id_raw( $term_id, $taxonomy ) {
		global $wpdb;
		$term_id = (int) $term_id;
		if ( $term_id < 1 ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s LIMIT 1",
			$term_id,
			$taxonomy
		) );
	}

	/**
	 * Find a term ID by slug without WPML language filtering.
	 *
	 * @param string $slug
	 * @param string $taxonomy
	 * @param string $lang
	 * @return int
	 */
	private function find_term_id_by_slug_unfiltered( $slug, $taxonomy, $lang ) {
		global $wpdb;
		$slug = (string) $slug;
		if ( $slug === '' || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT t.term_id FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
			WHERE t.slug = %s AND tt.taxonomy = %s
			ORDER BY t.term_id ASC",
			$slug,
			$taxonomy
		) );
		if ( empty( $candidates ) ) {
			return 0;
		}

		foreach ( $candidates as $candidate_id ) {
			$candidate_id = (int) $candidate_id;
			$in_lang = (int) apply_filters( 'wpml_object_id', $candidate_id, $taxonomy, false, $lang );
			if ( $in_lang === $candidate_id ) {
				return $candidate_id;
			}
		}

		return (int) apply_filters( 'wpml_object_id', (int) $candidates[0], $taxonomy, true, $lang );
	}

	public function add_plugin_page() {
		add_management_page(
			__( 'Themify Conditional Menus', 'themify-cm' ),
			__( 'Conditional Menus', 'themify-cm' ),
			'manage_options',
			'conditional-menus',
			array( $this, 'create_admin_page' ),
			99
		);
	}

	public function create_admin_page() {
		include( trailingslashit( plugin_dir_path( __FILE__ ) ) . 'docs/index.html' );
	}

	public static function activate( $network_wide ) {
		if( version_compare( get_bloginfo( 'version' ), '3.9', '<' ) ) {
			/* the plugin requires at least 3.9 */
			deactivate_plugins( basename( __FILE__ ) ); // Deactivate the plugin
		} else {
			if( ! $network_wide && ! isset( $_GET['activate-multi'] ) ) {
				add_option( 'themify_conditional_menus_activation_redirect', true );
			}
		}
	}

	public function activation_redirect() {
		if( get_option( 'themify_conditional_menus_activation_redirect', false ) ) {
			delete_option( 'themify_conditional_menus_activation_redirect' );
			wp_redirect( admin_url( 'admin.php?page=conditional-menus' ) );
		}
	}

	/**
	 * Disable WPML nav menu filtering in the Menu Locations manager
	 *
	 * @since 1.0.2
	 */
	public function wpml_after_startup() {
		global $pagenow;
		if( isset( $_GET['action'] ) && 'locations' === $_GET['action'] && is_admin() && $pagenow === 'nav-menus.php' ) {
		    remove_all_filters( 'get_terms', 1 );
		}
	}

	/**
	 * Remove menu assignments when the menu gets deleted
	 *
	 * @since 1.0.7
	 */
	function wp_delete_nav_menu( $menu_id ) {
		$options = get_theme_mod( 'themify_conditional_menus', array() );
		if( ! empty( $options ) ) {
			foreach( $options as $location => $assignments ) {
				if( is_array( $assignments ) && ! empty( $assignments ) ) {
					foreach( $assignments as $key => $menu ) {
						if( $menu['menu'] == $menu_id ) {
							unset( $options[$location][$key] );
						}
					}
				}
			}
		}
		set_theme_mod( 'themify_conditional_menus', $options );
	}
	
	private static function themify_enque($url){
	    static $is=null;
	    if($is===null){
		$is=  function_exists('themify_enque');
	    }
	    if($is===true){
		return themify_enque($url);
	    }
	    return $url;
	}
	
	private static function themify_enque_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all' ){
	    static $is=null;
	    if($is===null){
		$is=  function_exists('themify_is_themify_theme') && themify_is_themify_theme();
	    }
	    if($is===true){
		themify_enque_style($handle,$src,$deps,$ver,$media);
	    }
	    else{
		wp_enqueue_style($handle,$src,$deps,$ver,$media);
	    }
	}

    private static function update_non_ascii_slugs(array &$array ) {
        if(function_exists('mb_check_encoding')){
            foreach ( $array as &$value ) {
                if ( isset($value) && ! mb_check_encoding( $value, 'ASCII' ) ) {
                    /* revert "/" character, needed to check for nested posts */
                    $value= str_replace( '%2f', '/', strtolower( urlencode( $value ) ));
                }
            }
        }
    }
}
$themify_cm = new Themify_Conditional_Menus;
