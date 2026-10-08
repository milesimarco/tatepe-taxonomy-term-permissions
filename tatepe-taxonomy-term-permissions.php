<?php
/**
 * Plugin Name:       TATEPE – Taxonomy Term Permissions
 * Description:       Choose the taxonomy terms in which specific roles or users cannot publish.
 * Version:           1.0.0
 * Requires at least: 5.3
 * Requires PHP:      7.4
 * Author:            Marco Milesi
 * Author URI:        https://www.marcomilesi.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tatepe-taxonomy-term-permissions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Taxonomy_Permissions {

	const VERSION       = '1.0.0';
	const PAGE          = 'tatepe-taxonomy-term-permissions';
	const OPTION_ROLES  = 'taxonomy_permissions_roles';
	const OPTION_USERS  = 'taxonomy_permissions_users';
	const OPTION_DELETE = 'taxonomy_permissions_delete_on_uninstall';
	const RESET_ACTION  = 'taxonomy_permissions_reset';

	/**
	 * Excluded terms per user ID, computed once per request.
	 *
	 * @var array
	 */
	private $excluded = array();

	/**
	 * Terms grouped by parent, per taxonomy (settings page only).
	 *
	 * @var array
	 */
	private $trees = array();

	/**
	 * Whether term queries are temporarily left unfiltered.
	 *
	 * @var bool
	 */
	private $bypass = false;

	public function __construct() {
		add_filter( 'list_terms_exclusions', array( $this, 'filter_terms' ), 10, 3 );
		add_action( 'pre_get_posts', array( $this, 'filter_posts' ) );
		add_filter( 'map_meta_cap', array( $this, 'restrict_assign_term' ), 10, 4 );
		add_filter( 'wp_insert_post_data', array( $this, 'block_restricted_save' ), 10, 2 );
		add_action( 'deleted_user', array( $this, 'delete_user_rules' ) );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );
	}

	/*
	 * Rules
	 */

	/**
	 * Stored rules: array( role or user ID => array( taxonomy => term IDs ) ).
	 *
	 * @param string $option Option name.
	 * @return array
	 */
	private function get_rules( $option ) {
		$rules = get_option( $option, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Terms a user cannot use: their own rules if they have any, otherwise the
	 * rules of all their roles.
	 *
	 * @param WP_User $user User.
	 * @return array array( taxonomy => term IDs )
	 */
	public function get_excluded_terms( $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return array();
		}

		if ( isset( $this->excluded[ $user->ID ] ) ) {
			return $this->excluded[ $user->ID ];
		}

		$users = $this->get_rules( self::OPTION_USERS );

		if ( ! empty( $users[ $user->ID ] ) ) {
			$sources = array( $users[ $user->ID ] );
		} else {
			$roles   = $this->get_rules( self::OPTION_ROLES );
			$sources = array();
			foreach ( (array) $user->roles as $role ) {
				$sources[] = $roles[ $role ] ?? array();
			}
		}

		$excluded = array();
		foreach ( $sources as $rules ) {
			foreach ( (array) $rules as $taxonomy => $term_ids ) {
				$excluded[ $taxonomy ] = array_merge( $excluded[ $taxonomy ] ?? array(), (array) $term_ids );
			}
		}

		foreach ( $excluded as $taxonomy => $term_ids ) {
			$excluded[ $taxonomy ] = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
		}

		$this->excluded[ $user->ID ] = array_filter( $excluded );

		return $this->excluded[ $user->ID ];
	}

	/**
	 * Terms hidden from the current user. Users who can manage options are never restricted.
	 *
	 * @return array
	 */
	private function get_current_user_excluded_terms() {
		if ( ! did_action( 'init' ) || ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
			return array();
		}

		return $this->get_excluded_terms( wp_get_current_user() );
	}

	/*
	 * Restrictions
	 */

	/**
	 * Hide restricted terms from term queries in the admin area and the REST API.
	 *
	 * @param string $exclusions SQL exclusions clause.
	 * @param array  $args       Term query arguments.
	 * @param array  $taxonomies Queried taxonomies.
	 * @return string
	 */
	public function filter_terms( $exclusions, $args, $taxonomies ) {
		if ( $this->bypass || ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) ) {
			return $exclusions;
		}

		$excluded = $this->get_current_user_excluded_terms();

		if ( $excluded && $taxonomies ) {
			$excluded = array_intersect_key( $excluded, array_flip( (array) $taxonomies ) );
		}

		if ( ! $excluded ) {
			return $exclusions;
		}

		$term_ids = array_unique( array_map( 'absint', array_merge( ...array_values( $excluded ) ) ) );

		return $exclusions . ' AND t.term_id NOT IN (' . implode( ',', $term_ids ) . ')';
	}

	/**
	 * Hide posts assigned to restricted terms from the admin post lists.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public function filter_posts( $query ) {
		global $pagenow;

		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}

		$excluded = $this->get_current_user_excluded_terms();
		if ( ! $excluded ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$excluded  = array_intersect_key( $excluded, array_flip( get_object_taxonomies( $post_type ? $post_type : 'post' ) ) );
		if ( ! $excluded ) {
			return;
		}

		$tax_query = array( 'relation' => 'AND' );
		$existing  = $query->get( 'tax_query' );
		if ( is_array( $existing ) && $existing ) {
			$tax_query[] = $existing;
		}

		foreach ( $excluded as $taxonomy => $term_ids ) {
			$tax_query[] = array(
				'taxonomy'         => $taxonomy,
				'field'            => 'term_id',
				'terms'            => $term_ids,
				'operator'         => 'NOT IN',
				'include_children' => false,
			);
		}

		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Deny the "assign_term" capability on restricted terms, so that the REST API
	 * (block editor) refuses to save a post with one of them.
	 *
	 * @param string[] $caps    Required primitive capabilities.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Context, the term ID first.
	 * @return string[]
	 */
	public function restrict_assign_term( $caps, $cap, $user_id, $args ) {
		if ( 'assign_term' !== $cap || empty( $args[0] ) || get_current_user_id() !== (int) $user_id ) {
			return $caps;
		}

		foreach ( $this->get_current_user_excluded_terms() as $term_ids ) {
			if ( in_array( (int) $args[0], $term_ids, true ) ) {
				$caps[] = 'do_not_allow';
				break;
			}
		}

		return $caps;
	}

	/**
	 * Stop a save (classic editor, quick edit, bulk edit, XML-RPC) that would add
	 * a restricted term to a post. Nothing is written: the user gets an error.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public function block_restricted_save( $data, $postarr ) {
		$excluded = $this->get_current_user_excluded_terms();
		if ( ! $excluded ) {
			return $data;
		}

		$submitted = is_array( $postarr['tax_input'] ?? null ) ? $postarr['tax_input'] : array();
		foreach ( array( 'post_category' => 'category', 'tags_input' => 'post_tag' ) as $key => $taxonomy ) {
			if ( ! empty( $postarr[ $key ] ) ) {
				$submitted[ $taxonomy ] = array_merge( $this->to_list( $submitted[ $taxonomy ] ?? array() ), $this->to_list( $postarr[ $key ] ) );
			}
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$blocked = array();

		foreach ( $excluded as $taxonomy => $term_ids ) {
			if ( empty( $submitted[ $taxonomy ] ) ) {
				continue;
			}

			$values       = $this->to_list( $submitted[ $taxonomy ] );
			$hierarchical = is_taxonomy_hierarchical( $taxonomy );

			// Terms already on the post are not a new assignment.
			foreach ( array_diff( $term_ids, $this->get_object_term_ids( $post_id, $taxonomy ) ) as $term_id ) {
				$term = get_term( $term_id, $taxonomy );
				if ( ! $term instanceof WP_Term ) {
					continue;
				}

				foreach ( $values as $value ) {
					// Hierarchical terms are submitted by ID, the others by name.
					if ( is_int( $value ) || $hierarchical ) {
						$match = (int) $value === (int) $term->term_id;
					} else {
						$match = 0 === strcasecmp( trim( (string) $value ), $term->name );
					}

					if ( $match ) {
						$blocked[] = $term->name;
						break;
					}
				}
			}
		}

		if ( $blocked ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of term names. */
						__( 'You are not allowed to publish in: %s. Nothing has been saved.', 'tatepe-taxonomy-term-permissions' ),
						implode( ', ', $blocked )
					)
				),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		return $data;
	}

	/**
	 * @param mixed $terms Array of terms or comma-separated string.
	 * @return array
	 */
	private function to_list( $terms ) {
		return is_string( $terms ) ? explode( ',', $terms ) : (array) $terms;
	}

	/**
	 * Term IDs assigned to an object, restricted ones included.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $taxonomy  Taxonomy.
	 * @return int[]
	 */
	private function get_object_term_ids( $object_id, $taxonomy ) {
		if ( ! $object_id ) {
			return array();
		}

		$this->bypass = true;
		$term_ids     = wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'ids' ) );
		$this->bypass = false;

		return is_wp_error( $term_ids ) ? array() : array_map( 'intval', $term_ids );
	}

	/**
	 * Drop the rules of a deleted user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function delete_user_rules( $user_id ) {
		$users = $this->get_rules( self::OPTION_USERS );

		if ( isset( $users[ $user_id ] ) ) {
			unset( $users[ $user_id ] );
			update_option( self::OPTION_USERS, $users );
		}
	}

	/*
	 * Settings
	 */

	public function register_settings() {
		register_setting(
			'taxonomy_permissions_roles_group',
			self::OPTION_ROLES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_roles' ),
			)
		);
		register_setting(
			'taxonomy_permissions_users_group',
			self::OPTION_USERS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_users' ),
			)
		);
		register_setting(
			'taxonomy_permissions_uninstall_group',
			self::OPTION_DELETE,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);
	}

	/**
	 * @param mixed $rules array( taxonomy => term IDs ).
	 * @return array
	 */
	private function sanitize_rules( $rules ) {
		$clean = array();

		if ( ! is_array( $rules ) ) {
			return $clean;
		}

		foreach ( $rules as $taxonomy => $term_ids ) {
			$taxonomy = sanitize_key( $taxonomy );
			if ( ! taxonomy_exists( $taxonomy ) || ! is_array( $term_ids ) ) {
				continue;
			}

			$term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
			if ( $term_ids ) {
				$clean[ $taxonomy ] = $term_ids;
			}
		}

		return $clean;
	}

	/**
	 * @param mixed $input array( role => rules ).
	 * @return array
	 */
	public function sanitize_roles( $input ) {
		$clean = array();

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		foreach ( $input as $role => $rules ) {
			$role  = sanitize_key( $role );
			$rules = $this->sanitize_rules( $rules );
			if ( $rules && wp_roles()->is_role( $role ) ) {
				$clean[ $role ] = $rules;
			}
		}

		return $clean;
	}

	/**
	 * The settings form edits one user at a time and flags it with "_edited":
	 * only that user's rules are replaced, all the others are kept.
	 *
	 * @param mixed $input array( user ID => rules ).
	 * @return array
	 */
	public function sanitize_users( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}

		if ( isset( $input['_edited'] ) ) {
			$user_id = absint( $input['_edited'] );
			$users   = $this->get_rules( self::OPTION_USERS );
			$rules   = $this->sanitize_rules( $input[ $user_id ] ?? array() );

			if ( $rules && get_userdata( $user_id ) ) {
				$users[ $user_id ] = $rules;
			} else {
				unset( $users[ $user_id ] );
			}

			return $users;
		}

		$clean = array();
		foreach ( $input as $user_id => $rules ) {
			$user_id = absint( $user_id );
			$rules   = $this->sanitize_rules( $rules );
			if ( $user_id && $rules ) {
				$clean[ $user_id ] = $rules;
			}
		}

		return $clean;
	}

	public function handle_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'tatepe-taxonomy-term-permissions' ), 403 );
		}

		check_admin_referer( self::RESET_ACTION );

		delete_option( self::OPTION_ROLES );
		delete_option( self::OPTION_USERS );

		wp_safe_redirect( add_query_arg( 'reset', '1', $this->page_url() ) );
		exit;
	}

	/*
	 * Settings page
	 */

	public function add_admin_menu() {
		add_options_page(
			__( 'TATEPE – Taxonomy Term Permissions', 'tatepe-taxonomy-term-permissions' ),
			__( 'TATEPE', 'tatepe-taxonomy-term-permissions' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'tatepe-taxonomy-term-permissions-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_script( 'tatepe-taxonomy-term-permissions-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), self::VERSION, true );
	}

	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $this->page_url() ), esc_html__( 'Settings', 'tatepe-taxonomy-term-permissions' ) )
		);

		return $links;
	}

	/**
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	private function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation parameters.
		$tab     = ( isset( $_GET['tab'] ) && 'users' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) ? 'users' : 'roles';
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$reset   = isset( $_GET['reset'] );
		// phpcs:enable
		?>
		<div class="wrap taxperm">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( $reset ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All restrictions have been removed.', 'tatepe-taxonomy-term-permissions' ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'The role or user cannot publish in the checked terms: they are hidden, together with the posts assigned to them. Rules set for a single user replace the rules of their roles.', 'tatepe-taxonomy-term-permissions' ); ?>
				<?php esc_html_e( 'Users who can manage options are never restricted.', 'tatepe-taxonomy-term-permissions' ); ?>
			</p>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $this->page_url() ); ?>" class="nav-tab <?php echo 'roles' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Roles', 'tatepe-taxonomy-term-permissions' ); ?></a>
				<a href="<?php echo esc_url( $this->page_url( array( 'tab' => 'users' ) ) ); ?>" class="nav-tab <?php echo 'users' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Users', 'tatepe-taxonomy-term-permissions' ); ?></a>
			</h2>

			<?php
			if ( 'users' === $tab ) {
				$this->render_users_tab( $user_id );
			} else {
				$this->render_roles_tab();
			}
			?>

			<h2><?php esc_html_e( 'Reset', 'tatepe-taxonomy-term-permissions' ); ?></h2>
			<p><?php esc_html_e( 'Remove every restriction, for all roles and users. This cannot be undone.', 'tatepe-taxonomy-term-permissions' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_ACTION ); ?>" />
				<?php
				wp_nonce_field( self::RESET_ACTION );
				submit_button( __( 'Reset', 'tatepe-taxonomy-term-permissions' ), 'delete', 'submit', false );
				?>
			</form>

			<h2><?php esc_html_e( 'Uninstall', 'tatepe-taxonomy-term-permissions' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'taxonomy_permissions_uninstall_group' ); ?>
				<p>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION_DELETE ); ?>" value="1" <?php checked( (bool) get_option( self::OPTION_DELETE ) ); ?> />
						<?php esc_html_e( 'Delete all the rules when the plugin is deleted', 'tatepe-taxonomy-term-permissions' ); ?>
					</label>
				</p>
				<?php submit_button( __( 'Save', 'tatepe-taxonomy-term-permissions' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private function render_roles_tab() {
		$rules = $this->get_rules( self::OPTION_ROLES );
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'taxonomy_permissions_roles_group' );

			foreach ( get_editable_roles() as $role => $details ) {
				if ( ! empty( $details['capabilities']['manage_options'] ) ) {
					continue;
				}

				echo '<h3>' . esc_html( translate_user_role( $details['name'] ) ) . '</h3>';
				$this->render_boxes( self::OPTION_ROLES . '[' . $role . ']', $rules[ $role ] ?? array() );
			}

			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * @param int $user_id Selected user ID.
	 * @return void
	 */
	private function render_users_tab( $user_id ) {
		$rules = $this->get_rules( self::OPTION_USERS );
		$user  = $user_id ? get_userdata( $user_id ) : false;
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" class="taxperm-user-select">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
			<input type="hidden" name="tab" value="users" />
			<label for="taxperm-user"><?php esc_html_e( 'User', 'tatepe-taxonomy-term-permissions' ); ?></label>
			<?php
			wp_dropdown_users(
				array(
					'name'              => 'user',
					'id'                => 'taxperm-user',
					'selected'          => $user ? $user->ID : 0,
					'show_option_none'  => __( '— Select —', 'tatepe-taxonomy-term-permissions' ),
					'option_none_value' => 0,
				)
			);
			submit_button( __( 'Select', 'tatepe-taxonomy-term-permissions' ), 'secondary', '', false );
			?>
		</form>

		<?php if ( $rules ) : ?>
			<p>
				<?php
				esc_html_e( 'Users with their own rules:', 'tatepe-taxonomy-term-permissions' );
				$links = array();
				foreach ( array_keys( $rules ) as $id ) {
					$restricted = get_userdata( $id );
					if ( $restricted ) {
						$links[] = sprintf( '<a href="%s">%s</a>', esc_url( $this->page_url( array( 'tab' => 'users', 'user' => $id ) ) ), esc_html( $restricted->display_name ) );
					}
				}
				echo ' ' . wp_kses_post( implode( ', ', $links ) );
				?>
			</p>
		<?php endif; ?>

		<?php
		if ( ! $user ) {
			return;
		}

		if ( user_can( $user, 'manage_options' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This user can manage options and is never restricted.', 'tatepe-taxonomy-term-permissions' ) . '</p></div>';
			return;
		}

		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'taxonomy_permissions_users_group' ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::OPTION_USERS ); ?>[_edited]" value="<?php echo esc_attr( $user->ID ); ?>" />
			<h3><?php echo esc_html( $user->display_name ); ?></h3>
			<?php
			$this->render_boxes( self::OPTION_USERS . '[' . $user->ID . ']', $rules[ $user->ID ] ?? array() );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * One collapsible box of term checkboxes per taxonomy.
	 *
	 * @param string $name  Field name prefix.
	 * @param array  $rules array( taxonomy => selected term IDs ).
	 * @return void
	 */
	private function render_boxes( $name, $rules ) {
		echo '<div class="taxperm-boxes">';

		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $taxonomy ) {
			$tree = $this->get_terms_tree( $taxonomy->name );
			if ( ! $tree ) {
				continue;
			}

			$selected  = array_map( 'intval', (array) ( $rules[ $taxonomy->name ] ?? array() ) );
			$post_type = $taxonomy->object_type ? get_post_type_object( $taxonomy->object_type[0] ) : null;
			$label     = $post_type ? $post_type->label . ' – ' . $taxonomy->label : $taxonomy->label;
			?>
			<details class="taxperm-box<?php echo $selected ? ' has-rules' : ''; ?>">
				<summary>
					<?php echo esc_html( $label ); ?>
					<span class="taxperm-count">(<?php echo esc_html( count( $selected ) ); ?>)</span>
				</summary>
				<div class="taxperm-terms">
					<?php $this->render_terms( $tree, 0, $name . '[' . $taxonomy->name . '][]', $selected ); ?>
				</div>
			</details>
			<?php
		}

		echo '</div>';
	}

	/**
	 * @param string $taxonomy Taxonomy name.
	 * @return array array( parent term ID => terms )
	 */
	private function get_terms_tree( $taxonomy ) {
		if ( ! isset( $this->trees[ $taxonomy ] ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);

			$tree = array();
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$tree[ $term->parent ][] = $term;
				}
			}

			$this->trees[ $taxonomy ] = $tree;
		}

		return $this->trees[ $taxonomy ];
	}

	/**
	 * @param array  $tree     Terms grouped by parent.
	 * @param int    $parent   Parent term ID.
	 * @param string $name     Checkbox name.
	 * @param array  $selected Selected term IDs.
	 * @return void
	 */
	private function render_terms( $tree, $parent, $name, $selected ) {
		if ( empty( $tree[ $parent ] ) ) {
			return;
		}

		echo '<ul>';
		foreach ( $tree[ $parent ] as $term ) {
			printf(
				'<li><label><input type="checkbox" name="%1$s" value="%2$d"%3$s /> %4$s</label>',
				esc_attr( $name ),
				(int) $term->term_id,
				checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
				esc_html( $term->name )
			);
			$this->render_terms( $tree, $term->term_id, $name, $selected );
			echo '</li>';
		}
		echo '</ul>';
	}
}

new Taxonomy_Permissions();
