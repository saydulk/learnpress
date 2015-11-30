<?php
/**
 * Update LearnPress to 1.0
 */

if ( !defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class LP_Upgrade_10
 */
class LP_Upgrade_10 {
	/**
	 * All steps for update actions
	 *
	 * @var array
	 */
	protected $_steps = array(
		'welcome', 'upgraded'
	);

	/**
	 * Current step
	 *
	 * @var string
	 */
	protected $_current_step = '';

	public static $courses_map = array();
	public static $orders_map = array();
	public static $course_order_map = array();
	public static $quizzes_map = array();
	public static $questions_map = array();

	/**
	 * Constructor
	 */
	function __construct() {
		$this->_prevent_access_admin();
		$this->learn_press_upgrade_10_page();
	}

	private function _prevent_access_admin() {
		/*if( $this->check_post_types() || $this->check_admin_menu() ) {
			wp_redirect( admin_url( 'admin.php?page=learnpress_update_10' ) );
			exit;
		}*/
	}

	/**
	 * Check if user trying to access the old custom post type
	 *
	 * @return bool
	 */
	private function check_post_types() {
		$post_type = !empty( $_REQUEST['post_type'] ) ? $_REQUEST['post_type'] : '';
		if ( !$post_type ) {
			$post_id = !empty( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : 0;
			if ( $post_id ) {
				$post_type = get_post_field( $post_id, 'post_type' );
			}
		}
		$old_post_types = array( 'lpr_course', 'lpr_lesson', 'lpr_quiz', 'lpr_question', 'lpr_order', 'lpr_assignment' );
		return in_array( $post_type, $old_post_types );
	}

	/**
	 * Check if user trying to access LearnPress admin menu
	 *
	 * @return bool
	 */
	private function check_admin_menu() {
		$admin_page = !empty( $_REQUEST['page'] ) ? $_REQUEST['page'] : '';
		return preg_match( '!^learn_press_!', $admin_page );
	}

	private function _is_false_value( $value ) {
		if ( is_numeric( $value ) ) {
			return $value == 0;
		} elseif ( is_string( $value ) ) {
			return ( empty( $value ) || is_null( $value ) || in_array( $value, array( 'no', 'off', 'false' ) ) );
		}
		return !!$value;
	}

	private function _create_curriculum( $old_id, $new_id ) {
		global $wpdb;
		$curriculum    = get_post_meta( $old_id, '_lpr_course_lesson_quiz', true );
		$section_items = array();
		if ( $curriculum ) {
			foreach ( $curriculum as $order => $section ) {
				$result = $wpdb->insert(
					$wpdb->prefix . 'learnpress_sections',
					array(
						'section_name'      => $section['name'],
						'section_course_id' => $new_id,
						'section_order'     => $order + 1
					),
					array( '%s', '%d', '%d' )
				);
				if ( $result ) {
					$section_id  = $wpdb->insert_id;
					$lesson_quiz = !empty( $section['lesson_quiz'] ) ? $section['lesson_quiz'] : '';
					$lesson_quiz = self::get_posts_by_ids( $lesson_quiz );
					if ( !$lesson_quiz ) continue;
					$order = 1;
					foreach ( $lesson_quiz as $obj ) {
						if ( $obj['post_type'] == 'lpr_quiz' ) {
							$obj['post_type'] = 'lp_quiz';
						} elseif ( $obj['post_type'] == 'lpr_lesson' ) {
							$obj['post_type'] = 'lp_lesson';
						}
						$obj_id = $obj['ID'];
						unset( $obj['ID'] );
						$return = array();
						if ( $new_obj_id = wp_insert_post( $obj ) ) {
							$wpdb->insert(
								$wpdb->prefix . 'learnpress_section_items',
								array(
									'section_id'         => $section_id,
									'section_item_id'    => $new_obj_id,
									'section_item_order' => $order ++
								)
							);
							$return['id'] = $new_obj_id;
							if ( $obj['post_type'] == 'lp_quiz' ) {
								$this->_create_quiz_meta( $obj_id, $new_obj_id );
								$new_questions              = $this->_create_quiz_questions( $obj_id, $new_obj_id );
								$return['questions']        = $new_questions;
								self::$quizzes_map[$obj_id] = $new_obj_id;
							} elseif ( $obj['post_type'] == 'lp_lesson' ) {
								$this->_create_lesson_meta( $obj_id, $new_obj_id );
							}
						}
						$section_items[$obj_id] = $return;
					}
				}
			}
		}
		return $section_items;
	}

	private function _create_course_meta( $old_id, $new_id ) {
		$keys        = array(
			'_lpr_course_duration'           => '_lp_duration',
			'_lpr_course_number_student'     => '_lp_students',
			'_lpr_max_course_number_student' => '_lp_max_students',
			'_lpr_retake_course'             => '_lp_retake_count',
			'_lpr_course_final'              => '_lp_final_quiz',
			'_lpr_course_condition'          => '_lp_passing_condition',
			'_lpr_course_enrolled_require'   => '_lp_required_enroll',
			'_lpr_course_payment'            => '_lp_payment'
		);
		$course_meta = self::get_post_meta( $old_id, array_keys( $keys ) );
		if ( $course_meta ) foreach ( $course_meta as $meta ) {
			$new_key   = $keys[$meta['meta_key']];
			$new_value = $meta['meta_value'];
			switch ( $new_key ) {
				case '_lp_payment':
					if ( $new_value == 'free' ) {
						$new_value = 'no';
					}
					break;
				case '_lp_enroll_requirement':
					if ( $this->_is_false_value( $new_value ) ) {
						$new_value = 'no';
					} else {
						$new_value = 'yes';
					}
			}
			add_post_meta( $new_id, $new_key, $new_value );
		}
	}

	private function _create_quiz_meta( $old_id, $new_id ) {
		$keys      = array(
			'_lpr_quiz_questions'       => null,
			'_lpr_duration'             => '_lp_duration',
			'_lpr_retake_quiz'          => '_lp_retake_count',
			'_lpr_show_quiz_result'     => '_lp_show_result',
			'_lpr_show_question_answer' => '_lp_show_question_answer',
			'_lpr_course'               => null
		);
		$quiz_meta = self::get_post_meta( $old_id, array_keys( $keys ) );
		if ( $quiz_meta ) foreach ( $quiz_meta as $meta ) {
			if ( !$keys[$meta['meta_key']] ) {
				continue;
			}
			$new_key   = $keys[$meta['meta_key']];
			$new_value = $meta['meta_value'];
			switch ( $new_key ) {
				case '_lp_show_result':
				case '_lp_show_question_answer':
					if ( $this->_is_false_value( $new_value ) ) {
						$new_value = 'no';
					} else {
						$new_value = 'yes';
					}
			}
			add_post_meta( $new_id, $new_key, $new_value );
		}
	}

	private function _create_lesson_meta( $old_id, $new_id ) {
		$keys      = array(
			'_lpr_lesson_duration' => '_lp_duration',
			'_lpr_lesson_preview'  => '_lp_is_previewable',
			'_lpr_course'          => null
		);
		$quiz_meta = self::get_post_meta( $old_id, array_keys( $keys ) );
		if ( $quiz_meta ) foreach ( $quiz_meta as $meta ) {
			if ( !$keys[$meta['meta_key']] ) {
				continue;
			}
			$new_key   = $keys[$meta['meta_key']];
			$new_value = $meta['meta_value'];
			switch ( $new_key ) {
				case '_lp_is_previewable':
					if ( $this->_is_false_value( $new_value ) ) {
						$new_value = 'no';
					} else {
						$new_value = 'yes';
					}
			}
			add_post_meta( $new_id, $new_key, $new_value );
		}
	}

	private function _create_quiz_questions( $old_quiz_id, $new_quiz_id ) {
		$_items = get_post_meta( $old_quiz_id, '_lpr_quiz_questions', true );
		if ( !$_items ) {
			return 0;
		}
		$_items     = array_keys( $_items );
		$_questions = $this->get_posts_by_ids( $_items );
		if ( !$_questions ) {
			return 0;
		}
		global $wpdb;
		$new_questions = array();
		$order         = 0;
		foreach ( $_questions as $question ) {
			$post_data       = (array) $question;
			$old_question_id = $post_data['ID'];
			unset( $post_data['ID'] );
			$post_data['post_type'] = 'lp_question';
			$new_question_id        = wp_insert_post( $post_data );
			if ( $new_question_id ) {
				$wpdb->insert(
					$wpdb->prefix . 'learnpress_quiz_questions',
					array(
						'quiz_id'        => $new_quiz_id,
						'question_id'    => $new_question_id,
						'question_order' => ++ $order
					),
					array( '%d', '%d', '%d' )
				);
				$this->_create_question_meta( $old_question_id, $new_question_id );
				$new_questions[$old_question_id]       = $new_question_id;
				self::$questions_map[$old_question_id] = $new_question_id;
			}
		}
		return $new_questions;
	}

	private function _create_question( $old_id ) {

	}

	private function _create_question_meta( $old_id, $new_id ) {
		$keys          = array(
			'_lpr_question'      => null,
			'_lpr_question_mark' => '_lp_mark',
			'_lpr_duration'      => null
		);
		$question_meta = self::get_post_meta( $old_id, array_keys( $keys ) );
		if ( $question_meta ) foreach ( $question_meta as $meta ) {
			if ( !$keys[$meta['meta_key']] ) {
				continue;
			}
			$new_key   = $keys[$meta['meta_key']];
			$new_value = $meta['meta_value'];
			add_post_meta( $new_id, $new_key, $new_value );
		}

		$meta = get_post_meta( $old_id, '_lpr_question', true );
		if ( $meta ) {
			global $wpdb;
			if ( !empty( $meta['type'] ) ) {
				add_post_meta( $new_id, '_lp_type', $meta['type'] );
			}
			if ( !empty( $meta['answer'] ) ) {
				if ( in_array( $meta['type'], array( 'true_or_false', 'single_choice', 'multi_choice' ) ) ) {
					$ordering = 0;
					foreach ( $meta['answer'] as $order => $answer ) {
						$question_data = array(
							'text'    => $answer['text'],
							'value'   => $ordering,
							'is_true' => $this->_is_false_value( $answer['is_true'] ) ? 'no' : 'yes'
						);
						$wpdb->insert(
							$wpdb->prefix . 'learnpress_question_answers',
							array(
								'question_id' => $new_id,
								'answer_data' => serialize( $question_data ),
								'ordering'    => ++ $ordering
							),
							array( '%d', '%s', '%d' )
						);
					}
				}
			}
		}
	}

	private function _upgrade_course( $old_course ) {
		$course_args              = (array) $old_course;
		$course_args['post_type'] = 'lp_course';
		unset( $course_args['ID'] );
		$new_course_id = wp_insert_post( $course_args );
		$section_items = false;
		if ( $new_course_id ) {
			$section_items = $this->_create_curriculum( $old_course->ID, $new_course_id );
			$this->_create_course_meta( $old_course->ID, $new_course_id );
		}
		return array( 'id' => $new_course_id, 'section_items' => $section_items );
	}

	private function _upgrade_courses() {
		global $wpdb;
		$query = $wpdb->prepare( "
			SELECT DISTINCT p.*, pm.meta_value as upgraded
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			WHERE post_type = %s
			HAVING upgraded IS NULL
		", '_learn_press_upgraded', 'lpr_course' );

		$new_courses = array();

		if ( $old_courses = $wpdb->get_results( $query ) ) {
			foreach ( $old_courses as $old_course ) {
				$return                       = $this->_upgrade_course( $old_course );
				$new_courses[$old_course->ID] = $return;
				if ( $return['id'] ) {
					$wpdb->insert(
						$wpdb->prefix . 'postmeta',
						array(
							'post_id'    => $old_course->ID,
							'meta_key'   => '_learn_press_upgraded',
							'meta_value' => $return['id']
						)
					);
				}
			}
		}

		self::$courses_map = $new_courses;
		$posts             = array();
		foreach ( $new_courses as $c ) {
			$posts[] = $c['id'];
			if ( $c['section_items'] ) foreach ( $c['section_items'] as $si ) {
				$posts[] = $si['id'];
				if ( !empty( $si['questions'] ) ) {
					$posts = array_merge( $posts, $si['questions'] );
				}
			}
		}

		$this->_remove_unused_data();
	}

	private function _remove_unused_data() {
		global $wpdb;
		$query = $wpdb->prepare( "
			DELETE FROM {$wpdb->postmeta}
			USING {$wpdb->posts} INNER JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID={$wpdb->postmeta}.post_id
			WHERE
			{$wpdb->postmeta}.meta_key LIKE %s
			AND {$wpdb->posts}.post_type LIKE %s", '\_lpr\_%', 'lp\_%' );
		$wpdb->query( $query );
	}

	function get_posts_by_ids( $ids ) {
		global $wpdb;
		settype( $ids, 'array' );
		$query = "SELECT * FROM {$wpdb->posts} WHERE ID IN(" . join( ',', $ids ) . ")";
		return $wpdb->get_results( $query, ARRAY_A );
	}

	function get_post_meta( $post_id, $keys ) {
		global $wpdb;

		$query = $wpdb->prepare( "
			SELECT pm.*
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key in ('" . join( "','", $keys ) . "')
			AND pm.post_id = %d
		", absint( $post_id ) );
		return $wpdb->get_results( $query, ARRAY_A );
	}

	private function rollback_database() {
		global $wpdb;
		$query = "
			SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type IN('lp_course', 'lp_lesson', 'lp_quiz', 'lp_question', 'lp_assignment', 'lp_order' )
		";
		if ( $ids = $wpdb->get_col( $query ) ) {
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN(" . join( ",", $ids ) . ")" );
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN(" . join( ",", $ids ) . ")" );

			$wpdb->query( "DELETE FROM {$wpdb->learnpress_sections}" );
			$wpdb->query( "DELETE FROM {$wpdb->learnpress_section_items}" );
			$wpdb->query( "DELETE FROM {$wpdb->learnpress_quiz_history}" );
			$wpdb->query( "DELETE FROM {$wpdb->learnpress_user_course}" );
		}
		delete_option( 'learnpress_db_version' );
		die();
	}

	private function _upgrade_orders() {
		global $wpdb;
		$query  = $wpdb->prepare( "
			SELECT p.*, u.ID as user_id
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->usermeta} um ON um.meta_value = p.ID AND um.meta_key = %s
			INNER JOIN {$wpdb->users} u ON u.ID = um.user_id
		", '_lpr_order_id' );
		$orders = $wpdb->get_results( $query );
		if ( !$orders ) {
			return;
		}
		foreach ( $orders as $order ) {
			$order_data              = (array) $order;
			$order_data['post_type'] = 'lp_order';
			unset( $order_data['ID'] );
			$old_order_id = $order->ID;
			if ( $new_order_id = wp_insert_post( $order_data ) ) {
				$this->_create_order_meta( $old_order_id, $new_order_id );
			}
			self::$orders_map[$old_order_id] = $new_order_id;
		}
	}

	private function _create_order_meta( $old_id, $new_id ) {
		global $wpdb;
		$keys       = array(
			'_learn_press_transaction_method'    => '_payment_method',
			'_learn_press_customer_id'           => '_user_id',
			'_learn_press_customer_ip'           => '_user_ip_address',
			'_learn_press_order_items'           => null,
			'_learn_press_transaction_method_id' => '_transaction_id'
		);
		$order_meta = self::get_post_meta( $old_id, array_keys( $keys ) );
		if ( $order_meta ) foreach ( $order_meta as $meta ) {
			if ( '_learn_press_order_items' == $meta['meta_key'] ) {
				$order_data = maybe_unserialize( $meta['meta_value'] );
				if ( isset( $order_data->total ) ) {
					add_post_meta( $new_id, '_order_total', $order_data->total );
				} else {
					add_post_meta( $new_id, '_order_total', 0 );
				}

				if ( isset( $order_data->sub_total ) ) {
					add_post_meta( $new_id, '_order_subtotal', $order_data->sub_total );
				} else {
					add_post_meta( $new_id, '_order_subtotal', 0 );
				}

				if ( isset( $order_data->currency ) ) {
					add_post_meta( $new_id, '_order_currency', $order_data->currency );
				} else {
					add_post_meta( $new_id, '', 'USD' );
				}

				if ( isset( $order_data->products ) ) {
					foreach ( $order_data->products as $order_item ) {
						$old_course_id = $order_item['id'];
						$new_course_id = isset( self::$courses_map[$old_course_id] ) ? self::$courses_map[$old_course_id]['id'] : 0;
						if ( $new_course_id ) {
							$new_course    = get_post( $new_course_id );
							$r             = $wpdb->insert(
								$wpdb->prefix . 'learnpress_order_items',
								array(
									'order_item_name' => isset( $order_item['product_name'] ) ? $order_item['product_name'] : $new_course->post_title,
									'order_id'        => $new_id
								)
							);
							$order_item_id = $wpdb->insert_id;
							learn_press_add_order_item_meta( $order_item_id, '_course_id', $new_course->ID );
							learn_press_add_order_item_meta( $order_item_id, '_quantity', $order_item['quantity'] );
							learn_press_add_order_item_meta( $order_item_id, '_subtotal', $order_item['product_subtotal'] );
							learn_press_add_order_item_meta( $order_item_id, '_total', $order_item['product_subtotal'] );
						}
						if ( empty( self::$course_order_map[$old_course_id] ) ) {
							self::$course_order_map[$old_course_id] = array();
						}
						self::$course_order_map[$old_course_id][] = $old_id;
					}
				}

				add_post_meta( $new_id, '_prices_include_tax', 'no' );
				add_post_meta( $new_id, '_user_agent', '' );
				add_post_meta( $new_id, '_order_key', '' );
				add_post_meta( $new_id, '_transaction_fee', '0' );

				continue;
			}
			$new_key   = $keys[$meta['meta_key']];
			$new_value = $meta['meta_value'];
			if ( '_payment_method' == $new_key ) {
				$method_title = preg_replace( '!-!', ' ', $new_value );
				$method_title = ucwords( $method_title );
				add_post_meta( $new_id, '_payment_method_title', $method_title );
			}

			add_post_meta( $new_id, $new_key, $new_value );
		}
	}

	private function _upgrade_order_courses() {
		global $wpdb;
		$user_meta_keys = array(
			'_lpr_user_course',
			'_lpr_course_time',
			'_lpr_quiz_start_time',
			'_lpr_quiz_questions',
			'_lpr_quiz_current_question',
			'_lpr_quiz_question_answer',
			'_lpr_quiz_completed'
		);
		$fields         = array();
		$join           = array();
		$having         = array();
		$index          = 2;
		foreach ( $user_meta_keys as $key ) {
			$new_key  = preg_replace( '!_lpr_!', '', $key );
			$fields[] = sprintf( "T{$index}.meta_value AS %s", $new_key );
			$join[]   = $wpdb->prepare( "LEFT JOIN {$wpdb->usermeta} T{$index} ON T{$index}.user_id = T1.user_id AND T{$index}.meta_key = %s", $key );
			$having[] = $new_key . ' IS NOT NULL';
			$index ++;
		}
		$query          = sprintf( "
			SELECT distinct T1.user_id,
				%s
			FROM {$wpdb->usermeta} AS T1
				%s
			HAVING (
				%s
			)", join( ",\n", $fields ), join( "\n", $join ), join( "\nOR ", $having ) );
		$user_meta_rows = $wpdb->get_results( $query );
		if ( !$user_meta_rows ) {
			return;
		}
		foreach ( $user_meta_rows as $user_meta ) {
			$user_meta = $this->_parse_user_meta( $user_meta );
			if ( !empty( $user_meta->user_course ) && !empty( $user_meta->course_time ) ) {
				foreach ( $user_meta->user_course as $course_id ) {
					if ( !empty( self::$courses_map[$course_id] ) && !empty( $user_meta->course_time[$course_id] ) ) {
						$new_course_id   = self::$courses_map[$course_id]['id'];
						$course_time     = $user_meta->course_time[$course_id];
						$course_end_time = !empty( $course_time['end'] ) ? $course_time['end'] : '';
						if ( !empty( self::$course_order_map[$course_id] ) ) {
							$course_order = reset( self::$course_order_map[$course_id] );
						} else {
							$course_order = 0;
						}
						$wpdb->insert(
							$wpdb->prefix . 'learnpress_user_courses',
							array(
								'user_id'    => $user_meta->user_id,
								'course_id'  => $new_course_id,
								'start_time' => date( 'Y-m-d H:i:s', $course_time['start'] ),
								'end_time'   => $course_end_time ? date( 'Y-m-d H:i:s', $course_end_time ) : '',
								'status'     => $course_end_time ? 'completed' : 'started',
								'order_id'   => !empty( self::$orders_map[$course_order] ) ? self::$orders_map[$course_order] : ''
							)
						);
					}
				}
			}
			if ( !empty( $user_meta->quiz_start_time ) ) {
				foreach ( $user_meta->quiz_start_time as $old_quiz_id => $time ) {
					if ( empty( self::$quizzes_map[$old_quiz_id] ) ) {
						continue;
					}
					$wpdb->insert(
						$wpdb->prefix . "learnpress_user_quizzes",
						array(
							'user_id' => $user_meta->user_id,
							'quiz_id' => self::$quizzes_map[$old_quiz_id]
						)
					);
					$user_quiz_id = $wpdb->insert_id;
					if ( !$user_quiz_id ) {
						continue;
					}
					learn_press_add_user_quiz_meta( $user_quiz_id, 'start', $time );
					if ( !empty( $user_meta->quiz_completed ) ) {
						if ( !empty( $user_meta->quiz_completed[$old_quiz_id] ) ) {
							learn_press_add_user_quiz_meta( $user_quiz_id, 'end', $user_meta->quiz_completed[$old_quiz_id] );
							learn_press_add_user_quiz_meta( $user_quiz_id, 'status', 'completed' );
						}
					}
					if ( !empty( $user_meta->quiz_current_question ) ) {
						if ( !empty( $user_meta->quiz_current_question[$old_quiz_id] ) ) {
							learn_press_add_user_quiz_meta( $user_quiz_id, 'current_question', self::$questions_map[$user_meta->quiz_current_question[$old_quiz_id]] );
						}
					}
					if ( !empty( $user_meta->quiz_questions ) ) {
						if ( !empty( $user_meta->quiz_questions[$old_quiz_id] ) ) {
							$quiz_questions = array();
							foreach ( $user_meta->quiz_questions[$old_quiz_id] as $old_question_id ) {
								if ( !empty( self::$questions_map[$old_question_id] ) ) {
									$quiz_questions[] = self::$questions_map[$old_question_id];
								}
							}
							learn_press_add_user_quiz_meta( $user_quiz_id, 'questions', $quiz_questions );
						}
					}
					if ( !empty( $user_meta->quiz_question_answer ) ) {
						if ( !empty( $user_meta->quiz_question_answer[$old_quiz_id] ) ) {
							$question_answers = array();
							foreach ( $user_meta->quiz_question_answer[$old_quiz_id] as $old_question_id => $answer ) {
								if ( !empty( self::$questions_map[$old_question_id] ) ) {
									$question_answers[self::$questions_map[$old_question_id]] = $answer;
								}
							}
							learn_press_add_user_quiz_meta( $user_quiz_id, 'question_answers', $question_answers );
						}
					}
				}
			}
		}
	}

	private function _get_course_order_by_user( $user, $course ) {
		global $wpdb;
		$query = "
		";
	}

	private function _parse_user_meta( $meta ) {
		$origin_type = gettype( $meta );
		$meta        = (array) $meta;
		foreach ( $meta as $k => $v ) {
			$meta[$k] = maybe_unserialize( $v );
		}
		settype( $meta, $origin_type );
		return $meta;
	}

	private function _upgrade_user_roles(){
		global $wpdb;
		$query = $wpdb->prepare("
			SELECT um.*
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = %s
			WHERE um.meta_value LIKE %s
		", 'wp_capabilities', '%"lpr\_teacher"%');
		if( $rows = $wpdb->get_results( $query ) ){
			foreach( $rows as $row ){

				$user = new WP_User($row->user_id);
				$user->remove_role('lpr_teacher');
				$user->add_role('lp_teacher');
			}
		}
		remove_role( 'lpr_teacher' );
	}

	function do_upgrade() {
		global $wpdb;
		// start a transaction so we can rollback all as begin
		$wpdb->query( "START TRANSACTION;" );
		try {
			// update courses
			$this->_upgrade_courses();
			// update orders
			$this->_upgrade_orders();
			// orders
			$this->_upgrade_order_courses();
			// user roles
			$this->_upgrade_user_roles();
		} catch ( Exception $ex ) {
			$wpdb->query( "ROLLBACK;" );
			wp_die( $ex->getMessage() );
		}
		$wpdb->query( "COMMIT;" );
		update_option( 'learnpress_version', '1.0' );
		update_option( 'learnpress_db_version', '1.0' );
		return true;
	}

	/**
	 * Display update page content
	 */
	function learn_press_upgrade_10_page() {
		if ( empty( $_REQUEST['page'] ) || $_REQUEST['page'] != 'learn_press_upgrade_10' ) return;
		if ( empty( $_REQUEST['_wpnonce'] ) || !wp_verify_nonce( $_REQUEST['_wpnonce'], 'learn-press-upgrade' ) ) {
			wp_redirect( admin_url() );
			exit();
		}

		if ( !empty( $_POST['action'] ) && $_POST['action'] == 'upgrade' ) {
			if ( $this->do_upgrade() ) {
				$_REQUEST['step'] = 'upgraded';
			}
		}

		wp_enqueue_style( 'lp-update-10', LP()->plugin_url( 'assets/css/lp-update-10.css' ), array( 'dashicons', 'install' ) );
		wp_enqueue_script( 'lp-update-10', LP()->plugin_url( 'assets/js/lp-update-10.js' ), array( 'jquery' ) );

		add_action( 'learn_press_update_step_welcome', array( $this, 'update_welcome' ) );
		add_action( 'learn_press_update_step_upgraded', array( $this, 'update_upgraded' ) );

		$step = !empty( $_REQUEST['step'] ) ? $_REQUEST['step'] : 'welcome';
		if ( !in_array( $step, $this->_steps ) ) {
			$step = reset( $this->_steps );
		}
		$this->_current_step = $step;
		$view                = learn_press_get_admin_view( 'updates/1.0/update-10-wizard.php' );
		include_once $view;
		exit();
	}

	/**
	 * Add menu to make it work properly
	 */
	function learn_press_update_10_menu() {
		add_dashboard_page( '', '', 'manage_options', 'learnpress_update_10', '' );
	}

	/**
	 * Welcome step page
	 */
	function update_welcome() {
		$view = learn_press_get_admin_view( 'updates/1.0/step-welcome.php' );
		include $view;
	}

	function update_upgraded() {
		$view = learn_press_get_admin_view( 'updates/1.0/step-upgraded.php' );
		include $view;
	}

	/**
	 * Repair Database step page
	 */
	function update_repair_database() {
		$view = learn_press_get_admin_view( 'updates/1.0/step-repair-database.php' );
		include $view;
	}

	function next_link() {
		if ( $this->_current_step ) {
			if ( ( $pos = array_search( $this->_current_step, $this->_steps ) ) !== false ) {
				if ( $pos < sizeof( $this->_steps ) - 1 ) {
					$pos ++;
					return admin_url( 'admin.php?page=learn_press_upgrade_10&step=' . $this->_steps[$pos] );
				}
			}
		}
		return false;
	}

	function prev_link() {
		if ( $this->_current_step ) {
			if ( ( $pos = array_search( $this->_current_step, $this->_steps ) ) !== false ) {
				if ( $pos > 0 ) {
					$pos --;
					return admin_url( 'admin.php?page=learn_press_upgrade_10&step=' . $this->_steps[$pos] );
				}
			}
		}
		return false;
	}
}

new LP_Upgrade_10();

if ( !empty( $_REQUEST['learnpress_update_10'] ) ) {

// TODO: convert post types

// TODO: convert course meta

// TODO: convert lesson meta

// TODO: convert quiz meta

// TODO: convert question meta

// TODO: convert order meta

// TODO: convert assignment data

} else {

	//wp_redirect( admin_url( 'admin.php?page=learnpress_update_10' ) );
	//die();
}