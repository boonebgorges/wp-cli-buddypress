<?php
/**
 * Manage BuddyPress group members.
 *
 * @since 1.5.0
 */
class BPCLI_Group_Members extends BPCLI_Component {

	/**
	 * Group ID Object Key
	 *
	 * @var string
	 */
	protected $obj_id_key = 'group_id';

	/**
	 * Group Object Type
	 *
	 * @var string
	 */
	protected $obj_type = 'group';

	/**
	 * Add a member to a group.
	 *
	 * ## OPTIONS
	 *
	 * ## OPTIONS
	 *
	 * --group-id=<group>
	 * : Identifier for the group. Accepts either a slug or a numeric ID.
	 *
	 * --user-id=<user>
	 * : Identifier for the user. Accepts either a user_login or a numeric ID.
	 *
	 * [--role=<role>]
	 * : Group member role (member, mod, admin).
	 * ---
	 * Default: member
	 * ---
	 *
	 * [--porcelain]
	 * : Return only the added group member id.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp group member add --group-id=3 --user-id=10
	 *     Success: Added user #3 to group #3 as member.
	 *
	 *     $ wp bp group member add --group-id=bar --user-id=20 --role=mod
	 *     Success: Added user #20 to group #45 as mod.
	 */
	public function add( $args, $assoc_args ) {
		$group_id = $assoc_args['group-id'];

		// Check that group exists.
		if ( ! $this->group_exists( $group_id ) ) {
			WP_CLI::error( 'No group found by that slug or ID.' );
		}

		$user = $this->get_user_id_from_identifier( $assoc_args['user-id'] );

		if ( ! $user ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}

		// Sanitize role.
		$role = $assoc_args['role'];
		if ( empty( $role ) || ! in_array( $role, $this->group_roles(), true ) ) {
			$role = 'member';
		}

		$joined = groups_join_group( $group_id, $user->ID );

		if ( $joined ) {
			if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain' ) ) {
				WP_CLI::line( $user->ID );
			} else {
				if ( 'member' !== $role ) {
					groups_promote_member( $user->ID, $group_id, $role );
				}

				$success = sprintf(
					'Added user #%d to group #%d as %s.',
					$user->ID,
					$group_id,
					$role
				);
				WP_CLI::success( $success );
			}
		} else {
			WP_CLI::error( 'Could not add user to the group.' );
		}
	}

	/**
	 * Remove a member from a group.
	 *
	 * ## OPTIONS
	 *
	 * --group-id=<group>
	 * : Identifier for the group. Accepts either a slug or a numeric ID.
	 *
	 * --user-id=<user>
	 * : Identifier for the user. Accepts either a user_login or a numeric ID.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp group member remove --group-id=3 --user-id=10
	 *     Success: Member #10 removed from the group #3.
	 *
	 *     $ wp bp group member delete --group-id=foo --user-id=admin
	 *     Success: Member #545 removed from the group #12.
	 *
	 * @alias delete
	 */
	public function remove( $args, $assoc_args ) {
		$group_id = $assoc_args['group-id'];

		// Check that group exists.
		if ( ! $this->group_exists( $group_id ) ) {
			WP_CLI::error( 'No group found by that slug or ID.' );
		}

		$user = $this->get_user_id_from_identifier( $assoc_args['user-id'] );

		if ( ! $user ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}

		$member = new BP_Groups_Member( $user->ID, $group_id );

		// True on success.
		if ( $member->remove() ) {
			WP_CLI::success( sprintf( 'Member #%d removed from the group #%d.', $user->ID, $group_id ) );
		} else {
			WP_CLI::error( 'Could not remove member from the group.' );
		}
	}

	/**
	 * Get a list of group memberships.
	 *
	 * This command can be used to fetch a list of a user's groups (using the --user-id
	 * parameter) or a group's members (using the --group-id flag).
	 *
	 * ## OPTIONS
	 *
	 * <group-id>
	 * : Identifier for the group. Can be a numeric ID or the group slug.
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific signup fields.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - ids
	 *   - json
	 *   - count
	 *   - yaml
	 * ---
	 *
	 * [--<field>=<value>]
	 * : One or more parameters to pass. See groups_get_group_members()
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp group member list 3
	 *     $ wp bp group member list my-group
	 *
	 * @subcommand list
	 */
	public function _list( $args, $assoc_args ) {
		$group_id = intval( $args[0] );

		// Check that group exists.
		if ( ! $this->group_exists( $group_id ) ) {
			WP_CLI::error( 'No group found by that slug or ID.' );
		}

		$roles = array( 'members' );
		if ( isset( $assoc_args['role'] ) ) {
			if ( is_string( $assoc_args['role'] ) ) {
				$roles = explode( ',', $assoc_args['role'] );
			} else {
				$roles = $assoc_args['role'];
			}
		}

		// Get our members.
		$members_query = groups_get_group_members( array(
			'group_id'            => $group_id,
			'exclude_admins_mods' => false,
			'group_role'          => $roles,
		) );
		$members = $members_query['members'];

		// Make 'role' human-readable.
		foreach ( $members as &$member ) {
			$role = 'member';
			if ( $member->is_mod ) {
				$role = 'mod';
			} elseif ( $member->is_admin ) {
				$role = 'admin';
			}

			$member->role = $role;
		}

		if ( empty( $members ) ) {
			WP_CLI::error( 'No group members found.' );
		}

		if ( empty( $assoc_args['fields'] ) ) {
			$fields = array(
				'user_id',
				'user_login',
				'fullname',
				'date_modified',
				'role',
			);

			$assoc_args['fields'] = $fields;
		}

		$formatter = $this->get_formatter( $assoc_args );
		$formatter->display_items( $members );
	}

	/**
	 * Get a list of groups a user is a member of.
	 *
	 * ## OPTIONS
	 *
	 * --user-id=<user>
	 * : Identifier for the user. Accepts either a user_login or a numeric ID.
	 *
	 * [--<field>=<value>]
	 * : One or more parameters to pass. See bp_get_user_groups()
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp group member get_groups --user-id=30
	 *     Success: Found 10 group(s) from member #30.
	 *     Success: Current group(s) from member #30: 156,454,545
	 *
	 * @alias list_groups
	 */
	public function get_groups( $args, $assoc_args ) {
		$r = wp_parse_args( $assoc_args, array(
			'is-confirmed' => true,
			'is-banned'    => false,
			'is-admin'     => null,
			'is-mod'       => null,
			'invite-sent'  => null,
			'orderby'      => 'group_id',
			'order'        => 'ASC',
		) );

		$user = $this->get_user_id_from_identifier( $assoc_args['user-id'] );

		if ( ! $user ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}

		$groups = bp_get_user_groups( $user->ID, array(
			'is_confirmed' => $r['is-confirmed'],
			'is_banned'    => $r['is-banned'],
			'is_admin'     => $r['is-admin'],
			'is_mod'       => $r['is-mod'],
			'invite_sent'  => $r['invite-sent'],
			'orderby'      => $r['orderby'],
			'order'        => $r['order'],
		) );

		if ( ! empty( $groups ) ) {
			$found = sprintf(
				'Found %d group(s) from member #%d.',
				count( $groups ),
				$user->ID
			);
			WP_CLI::success( $found );

			$success = sprintf(
				'Current group(s) from member #%d: %s.',
				$user->ID,
				implode( ', ', wp_list_pluck( $groups, 'group_id' ) )
			);
			WP_CLI::success( $success );
		} else {
			WP_CLI::error( 'This user is not a member of any group.' );
		}
	}

	/**
	 * Promote a member to a new status within a group.
	 *
	 * ## OPTIONS
	 *
	 * --group-id=<group>
	 * : Identifier for the group. Accepts either a slug or a numeric ID.
	 *
	 * --user-id=<user>
	 * : Identifier for the user. Accepts either a user_login or a numeric ID.
	 *
	 * --role=<role>
	 * : Group role to promote the member (mod, admin).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp group member promote --group-id=3 --user-id=10 --role=admin
	 *     Success: Member promoted to new role successfully.
	 *
	 *     $ wp bp group member promote --group-id=foo --user-id=admin --role=mod
	 *     Success: Member promoted to new role successfully.
	 */
	public function promote( $args, $assoc_args ) {
		$group_id = $assoc_args['group-id'];

		// Check that group exists.
		if ( ! $this->group_exists( $group_id ) ) {
			WP_CLI::error( 'No group found by that slug or ID.' );
		}

		$user = $this->get_user_id_from_identifier( $assoc_args['user-id'] );

		if ( ! $user ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}

		$role = $assoc_args['role'];
		if ( ! in_array( $role, $this->group_roles(), true ) ) {
			WP_CLI::error( 'You need a valid role to promote the member.' );
		}

		$member = new BP_Groups_Member( $user->ID, $group_id );

		if ( $member->promote( $role ) ) {
			WP_CLI::success( 'Member promoted to new role successfully.' );
		} else {
			WP_CLI::error( 'Could not promote the member.' );
		}
	}

	/**
	 * Demote user to the 'member' status.
	 *
	 * ## OPTIONS
	 *
	 * --group-id=<group>
	 * : Identifier for the group. Accepts either a slug or a numeric ID.
	 *
	 * --user-id=<user>
	 * : Identifier for the user. Accepts either a user_login or a numeric ID.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp group member demote --group-id=3 --user-id=10
	 *     Success: User demoted to the "member" status.
	 *
	 *     $ wp bp group member demote --group-id=foo --user-id=admin
	 *     Success: User demoted to the "member" status.
	 */
	public function demote( $args, $assoc_args ) {
		$group_id = $assoc_args['group-id'];

		// Check that group exists.
		if ( ! $this->group_exists( $group_id ) ) {
			WP_CLI::error( 'No group found by that slug or ID.' );
		}

		$user = $this->get_user_id_from_identifier( $assoc_args['user-id'] );

		if ( ! $user ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}

		$member = new BP_Groups_Member( $user->ID, $group_id );

		if ( $member->demote() ) {
			WP_CLI::success( 'User demoted to the "member" status.' );
		} else {
			WP_CLI::error( 'Could not demote the member.' );
		}
	}

	/**
	 * Ban a member from a group.
	 *
	 * ## OPTIONS
	 *
	 * --group-id=<group>
	 * : Identifier for the group. Accepts either a slug or a numeric ID.
	 *
	 * --user-id=<user>
	 * : Identifier for the user. Accepts either a user_login or a numeric ID.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp group member ban --group-id=3 --user-id=10
	 *     Success: Member banned from the group.
	 *
	 *     $ wp bp group member ban --group-id=foo --user-id=admin
	 *     Success: Member banned from the group.
	 */
	public function ban( $args, $assoc_args ) {
		$group_id = $assoc_args['group-id'];

		// Check that group exists.
		if ( ! $this->group_exists( $group_id ) ) {
			WP_CLI::error( 'No group found by that slug or ID.' );
		}

		$user = $this->get_user_id_from_identifier( $assoc_args['user-id'] );

		if ( ! $user ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}

		$member = new BP_Groups_Member( $user->ID, $group_id );

		if ( $member->ban() ) {
			WP_CLI::success( 'Member banned from the group.' );
		} else {
			WP_CLI::error( 'Could not ban the member.' );
		}
	}

	/**
	 * Unban a member from a group.
	 *
	 * ## OPTIONS
	 *
	 * --group-id=<group>
	 * : Identifier for the group. Accepts either a slug or a numeric ID.
	 *
	 * --user-id=<user>
	 * : Identifier for the user. Accepts either a user_login or a numeric ID.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp group member unban --group-id=3 --user-id=10
	 *     Success: Member unbanned from the group.
	 *
	 *     $ wp bp group member unban --group-id=foo --user-id=admin
	 *     Success: Member unbanned from the group.
	 */
	public function unban( $args, $assoc_args ) {
		$group_id = $assoc_args['group-id'];

		// Check that group exists.
		if ( ! $this->group_exists( $group_id ) ) {
			WP_CLI::error( 'No group found by that slug or ID.' );
		}

		$user = $this->get_user_id_from_identifier( $assoc_args['user-id'] );

		if ( ! $user ) {
			WP_CLI::error( 'No user found by that username or ID.' );
		}

		$member = new BP_Groups_Member( $user->ID, $group_id );

		if ( $member->unban() ) {
			WP_CLI::success( 'Member unbanned from the group.' );
		} else {
			WP_CLI::error( 'Could not unban the member.' );
		}
	}

	/**
	 * Group Roles.
	 *
	 * @since 1.5.0
	 *
	 * @return array An array of group roles.
	 */
	protected function group_roles() {
		return array( 'member', 'mod', 'admin' );
	}
}

WP_CLI::add_command( 'bp group member', 'BPCLI_Group_Members', array(
	'before_invoke' => function() {
		if ( ! bp_is_active( 'groups' ) ) {
			WP_CLI::error( 'The Groups component is not active.' );
		}
	},
) );
