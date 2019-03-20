<?php

/**
 * CiviCRM Caldera Forms Participant Processor Class.
 * @since 1.0
 */
class CiviCRM_Caldera_Forms_Participant_Processor {

	/**
	 * Plugin reference.
	 *
	 * @since 1.0
	 * @access public
	 * @var object $plugin The plugin instance
	 */
	public $plugin;

	/**
	 * Contact link.
	 * 
	 * @since 1.0
	 * @access protected
	 * @var string $contact_link The contact link
	 */
	protected $contact_link;

	/**
	 * Event Ids, array holding event ids indexed by procesor id.
	 *
	 * @access public
	 * @since 1.0
	 * @var array $event_ids
	 */
	public $event_ids;

	/**
	 * Events data, array holding event settings indexed by processor id.
	 *
	 * @access public
	 * @since 1.0
	 * @var array $events
	 */
	public $events;

	public $event_cividiscounts;

	public $options_cividiscounts;

	public $discounts_used;

	/**
	 * Current registration for a contact (participant data).
	 *
	 * @access public
	 * @since 1.0
	 * @var array $registrations
	 */
	public $registrations;

	/**
	 * Reference to the form fields set as price field options, indexed by processor id.
	 *
	 * @access public
	 * @since 1.0
	 * @var array $price_field_refs
	 */
	public $price_field_refs = [];

	/**
	 * The processor key.
	 *
	 * @since 1.0
	 * @access public
	 * @var string $key_name The processor key
	 */
	public $key_name = 'civicrm_participant';

	/**
	 * Initialises this object.
	 *
	 * @since 1.0
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		// register this processor
		add_filter( 'caldera_forms_get_form_processors', array( $this, 'register_processor' ) );

		// build price field references, at both render and submission start
		add_filter( 'caldera_forms_render_get_form', [ $this, 'get_set_necessary_data' ] );
		add_filter( 'caldera_forms_submit_get_form', [ $this, 'get_set_necessary_data' ], 20 );

		// filter price fields for notices and discount price fields
		add_filter( 'cfc_filter_price_field_config', [ $this, 'filter_price_field_config' ], 10, 4 );
		add_filter( 'cfc_filter_price_field_structure', [ $this, 'render_notices_for_paid_events' ], 10, 3 );

		// filter form when it renders
		add_filter( 'caldera_forms_render_get_form', [ $this, 'pre_render' ] );

		add_filter( 'cfc_custom_fields_extends_entities', [ $this, 'custom_fields_extend_participant' ] );

	}

	/**
	 * Adds this processor to Caldera Forms.
	 *
	 * @since 1.0
	 * @uses 'caldera_forms_get_form_processors' filter
	 * @param array $processors The existing processors
	 * @return array $processors The modified processors
	 */
	public function register_processor( $processors ) {

		$processors[$this->key_name] = [
			'name' => __( 'CiviCRM Participant', 'caldera-forms-civicrm' ),
			'description' => __( 'Add CiviCRM Participant to event (for Event registration).', 'caldera-forms-civicrm' ),
			'author' => 'Andrei Mondoc',
			'template' => CF_CIVICRM_INTEGRATION_PATH . 'processors/participant/config.php',
			'pre_processor' => [ $this, 'pre_processor' ],
			'processor' => [ $this, 'processor' ],
			'magic_tags' => [ 'processor_id' ]
		];

		return $processors;

	}

	/**
	 * Form pre processor callback.
	 *
	 * @since 1.0
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param string $processid The process id
	 */
	public function pre_processor( $config, $form, $processid ) {

		// cfc transient object
		$transient = $this->plugin->transient->get();
		$this->contact_link = 'cid_' . $config['contact_link'];

		// Get form values
		$form_values = $this->plugin->helper->map_fields_to_processor( $config, $form, $form_values );


		if ( ! empty( $transient->contacts->{$this->contact_link} ) ) {
			// event
			$event = $this->events[$config['processor_id']];

			$form_values['contact_id'] = $transient->contacts->{$this->contact_link};
			$form_values['event_id'] = $config['id'];
			$form_values['role_id'] = ( $config['role_id'] == 'default_role_id' ) ? $event['default_role_id'] : $config['role_id'];
			$form_values['status_id'] = ( $config['status_id'] == 'default_status_id' ) ? 'Registered' : $config['status_id']; // default is registered

			if ( ! empty( $config['campaign_id'] ) ) $form_values['campaign_id'] = $config['campaign_id'];

			// if multiple participant processors, we need to update $this->registrations
			$this->registrations = $this->get_participant_registrations( $this->event_ids, $form );

			$is_registered = is_array( $this->registrations[$config['processor_id']] );

			// prevent re-registrations based on event's 'allow_same_participant_emails' setting
			if ( $this->is_registered_and_same_email_allowed( $is_registered, $event ) ) {
				$notice = $this->get_notice( $config['processor_id'], $form );
				return $notice;
			}

			// store data in transient if is not registered
			if ( ! $is_registered || $this->is_registered_and_same_email_allowed( $is_registered, $event ) ) {
				$transient->participants->{$config['processor_id']}->params = $form_values;
				$this->plugin->transient->save( $transient->ID, $transient );

				if ( isset( $config['is_email_receipt'] ) ) {

					add_action( 'cfc_order_post_processor', function( $order, $order_config, $form, $processid ) use ( $event, $config ) {

						if ( ! $order ) return;

						foreach ( $order['line_items'] as $key => $item ) {

							if ( $item['entity_table'] == 'civicrm_participant' ) {

								$participant = civicrm_api3( 'Participant', 'get', [ 'id' => $item['entity_id'] ] );

								if ( is_array( $participant ) && ! $participant['is_error'] && $participant['values'][$item['entity_id']]['event_id'] == $event['id'] ) {

									$this->send_mail( $participant['values'][$participant['id']], $event, $order );
									break;
								}

							}

						}

					}, 10, 4 );

				}
			}

			if ( ( ! $config['is_monetary'] && ! $is_registered ) || ( ! $config['is_monetary'] && $this->is_registered_and_same_email_allowed( $is_registered, $event ) ) ) {
				try {
					$create_participant = civicrm_api3( 'Participant', 'create', $form_values );
					if ( ! $create_participant['is_error'] && $config['is_email_receipt'] ) {
						$this->send_mail( $create_participant['values'][$create_participant['id']], $event );
					}
				} catch ( CiviCRM_API3_Exception $e ) {
					$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
					return [ 'note' => $error, 'type' => 'error' ];
				}
			}
		}

	}

	/**
	 * Form processor callback.
	 *
	 * @since 1.0
	 * @param array $config Processor configuration
	 * @param array $form Form configuration
	 * @param string $processid The process id
	 */
	public function processor( $config, $form, $porcessid ) {
		return [ 'processor_id' => $config['processor_id'] ];
	}

	/**
	 * Autopopulates Form with Civi data.
	 *
	 * @uses 'caldera_forms_render_get_form' filter
	 * @since 1.0
	 * @param array $form The form
	 * @return array $form The modified form
	 */
	public function pre_render( $form ) {

		// render notices for non paid events
		$this->render_notices_for_non_paid_events( $form );

		return $form;
	}

	/**
	 * Get and set necessary data.
	 *
	 * @since 1.0
	 * @param array $form The form config
	 * @return array $form The form config
	 */
	public function get_set_necessary_data( $form ) {

		// participant processors
		$participants = $this->plugin->helper->get_processor_by_type( 'civicrm_participant', $form );
		// bail early
		if ( ! $participants ) return $form;
		// get event ids
		$this->event_ids = $this->get_events_ids( $participants );
		// get events data
		$this->events = $this->get_events_config( $this->event_ids );
		// build price field references
		$this->price_field_refs = $this->build_price_field_refs( $form );
		// get event registrations
		$this->registrations = $this->get_participant_registrations( $this->event_ids, $form );
		// get cividiscounts
		if ( isset( $this->plugin->cividiscount ) ) {
			$this->event_cividiscounts = $this->plugin->cividiscount->get_event_cividiscounts( $this->event_ids );
			$this->price_field_option_refs = $this->plugin->cividiscount->build_options_ids_refs( $this->price_field_refs, $form );
			$this->options_cividiscounts = $this->plugin->cividiscount->get_options_cividiscounts( $this->price_field_option_refs );
		}

		return $form;
	}

	/**
	 * Build Price Field fields references from Line Item processors for paid events.
	 *
	 * @since 1.0
	 * @param array $form The form config
	 * @return array|boolean $price_field_ref References to [ <processor_id> => <field_id> ], or false
	 */
	public function build_price_field_refs( $form ) {

		// line item processors
		$line_items = $this->plugin->helper->get_processor_by_type( 'civicrm_line_item', $form );

		if ( ! $line_items ) return false;

		$rendered_fields = array_reduce( $form['fields'], function( $fields, $field ) use ( $form ) {
			$config = Caldera_Forms_Field_Util::get_field( $field['ID'], $form, true );
			$fields[] = $config['slug'];
			return $fields;
		}, [] );

		return array_reduce( $line_items, function( $refs, $line_item ) use ( $form, $rendered_fields ) {

			if ( ! empty( $line_item['config']['entity_table'] ) && in_array( $line_item['config']['entity_table'], [ 'civicrm_participant', 'civicrm_membership' ] ) ) {

				$price_field_slug = $line_item['config']['price_field_value'];

				if ( strpos( $price_field_slug, '%' ) !== false && substr_count( $price_field_slug, '%' ) > 2 ) {

					$price_field_slug = array_filter( explode( '%', $price_field_slug ) );

					$price_field_slug = array_intersect( $price_field_slug, $rendered_fields );

				} else {

					$price_field_slug = str_replace( '%', '', $price_field_slug );

				}

				// participant processor id
				$participant_pid = $this->plugin->helper->get_processor_from_magic( $line_item['config']['entity_params'], $form );

				// there's no entity_params for civicrm_contribution line_items
				if ( ! $participant_pid )
					$participant_pid = $line_item['ID'];

				// price_field field config
				if ( is_array( $price_field_slug ) ) {

					if ( count( $price_field_slug ) > 1 ) {
						foreach ( $price_field_slug as $key => $field_id ) {

							if ( $key == 0 ) {

								$price_field_field = Caldera_Forms_Field_Util::get_field_by_slug( $field_id, $form );

								$refs[$participant_pid] = $price_field_field['ID'];
							} else {

								$price_field_field = Caldera_Forms_Field_Util::get_field_by_slug( $field_id, $form );

								$refs[$participant_pid . '#' . $key ] = $price_field_field['ID'];
							}

						}
					} else {

						$price_field_slug = array_pop( $price_field_slug );

						$price_field_field = Caldera_Forms_Field_Util::get_field_by_slug( $price_field_slug, $form );

						$refs[$participant_pid] = $price_field_field['ID'];
					}

				} else {

					$price_field_field = Caldera_Forms_Field_Util::get_field_by_slug( $price_field_slug, $form );

					$refs[$participant_pid] = $price_field_field['ID'];

				}

			}

			return $refs;

		}, [] );

	}

	/**
	 * Get events ids.
	 * 
	 * @since 1.0
	 * @param array $participant_processors Array holding participant processor config
	 * @return array|boolean $event_ids References to [ <processor_id> => <event_id> ], or false
	 */
	public function get_events_ids( $participant_processors ) {

		if ( ! $participant_processors ) return false;

		// event ids set in form's participant processors
		return array_reduce( $participant_processors, function( $event_ids, $processor ) {
			$event_ids[$processor['ID']] = $processor['config']['id'];
			return $event_ids;
		}, [] );

	}

	/**
	 * Get events settings.
	 *
	 * @since 1.0
	 * @param array $event_ids Array of event ids to get the settings for
	 * @return array|boolean $events References to [ <processor_id> => (array)<event_data> ], or false
	 */
	public function get_events_config( $event_ids ) {

		if ( ! $event_ids ) return false;

		try {
			$events_result = civicrm_api3( 'Event', 'get', [
				'id' => [ 'IN' => array_values( $event_ids ) ]
			] );
		} catch ( CiviCRM_API3_Exception $e ) {

		}

		if ( $events_result['count'] )
			return array_reduce( array_keys( $event_ids ), function( $events, $processor_id ) use ( $event_ids, $events_result ) {
				$event = $events_result['values'][$event_ids[$processor_id]];
				$event['participant_count'] = CRM_Event_BAO_Event::getParticipantCount( $event_ids[$processor_id] );
				$events[$processor_id] = $event;
				return $events;
			}, [] );

		return false;
	}

	/**
	 * Check and filter discounted price fields options.
	 *
	 * @since 1.0
	 * @param array $field The field structure
	 * @param array $form The form config
	 * @param array $price_field Price field and it's price_field_values
	 * @param string $current_filter The current filter 
	 * @return array $field The field structure 
	 */
	public function filter_price_field_config( $field, $form, $price_field, $current_filter ) {

		if ( ! $this->price_field_refs ) return $field;

		if ( ! array_search( $field['ID'], $this->price_field_refs ) ) return $field;

		array_map( function( $processor_id, $field_id ) use ( &$field, $form, $price_field, $current_filter ) {

			if ( $field_id != $field['ID'] ) return;

			$processor_id = $this->parse_processor_id( $processor_id );

			$processor = $form['processors'][$processor_id];

			$notice = $this->get_notice( $processor_id, $form );

			$field['config']['option'] = array_reduce( $price_field['price_field_values'], function( $options, $price_field_value ) use ( &$field, $notice, $processor ) {

				$option = $field['config']['option'][$price_field_value['id']];
				// disable option and make sure field is not required
				if ( $notice && $notice['disabled'] ) {
					$option['disabled'] = true;
					$field['required'] = 0;

					// set disable all fields flag
					if ( isset( $processor['config']['disable_all_fields'] ) ) $this->plugin->fields->presets_objects['civicrm_price_sets']->disable_all_fields = true;

				}

				$options[$price_field_value['id']] = $option;

				return $options;
			}, [] );

			// check for discounted price field events
			$field = $this->handle_discounted_events( $field, $form, $processor_id, $price_field );

			// do event cividiscounts
			if ( isset( $this->plugin->cividiscount ) )
				$field = $this->do_event_autodiscounts( $field, $form, $processor_id, $price_field );

			if ( $current_filter != 'caldera_forms_render_field_structure' )
				$field = $this->do_event_code_discounts( $field, $form, $processor_id, $price_field );

			$field = $this->handle_max_count_participants( $field, $form, $processor_id, $price_field );

			return $field;

		}, array_keys( $this->price_field_refs ), $this->price_field_refs );

		$field = $this->do_options_autodiscounts( $field, $form, $price_field, $current_filter );
		$field = $this->do_options_code_discounts( $field, $form, $price_field, $current_filter );

		return $field;
	}

	/**
	 * Filter price field options for discounted events.
	 *
	 * @since 1.0
	 * @param array $field Field config
	 * @param array $form Form cofig
	 * @param string $processor_id Processor id
	 * @param array $price_field The price field and it's price field values
	 * @return array $field The filtered field
	 */
	public function handle_discounted_events( $field, $form, $processor_id, $price_field ) {

		$processor_id = $this->parse_processor_id( $processor_id );

		// processor config
		$processor = $form['processors'][$processor_id];

		if ( $processor['type'] != $this->key_name ) return $field;

		$event_id = $processor['config']['id'];

		$discount_entity_id = CRM_Core_BAO_Discount::findSet( $event_id, 'civicrm_event' );

		if ( ! $discount_entity_id ) return $field;

		// get price set id for discounted price set
		$price_set_id = CRM_Core_DAO::getFieldValue( 'CRM_Core_BAO_Discount', $discount_entity_id, 'price_set_id' );

		$price_set = $this->plugin->helper->cached_price_sets()[$price_set_id];

		// discounted price field
		$price_field = array_pop( $price_set['price_fields'] );

		// filter field options
		$field['config']['option'] = array_reduce( $price_field['price_field_values'], function( $options, $price_field_value ) use ( $field ) {

			$option = [
				'value' => $price_field_value['id'],
				'label' => sprintf( '%1$s - %2$s', $price_field_value['label'], $this->plugin->helper->format_money( $price_field_value['amount'] ) ),
				'calc_value' => $price_field_value['amount']
			];

			if ( $price_field_value['tax_amount'] && $this->plugin->helper->get_tax_settings()['invoicing'] ) {
				$option['calc_value'] += $price_field_value['tax_amount'];
				$option['label'] = $this->plugin->helper->format_tax_label( $price_field_value['label'], $price_field_value['amount'], $price_field_value['tax_amount'] );
			}

			$options[$price_field_value['id']] = $option;

			return $options;
		}, [] );

		return $field;
	}

	/**
	 * Filter price field options for discounted events.
	 *
	 * @since 1.0
	 * @param array $field Field config
	 * @param array $form Form cofig
	 * @param string $processor_id Processor id
	 * @param array $price_field The price field and it's price field values
	 * @return array $field The filtered field
	 */
	public function handle_max_count_participants( $field, $form, $processor_id, $price_field ) {

		$processor_id = $this->parse_processor_id( $processor_id );

		// processor config
		$processor = $form['processors'][$processor_id];

		if ( $processor['type'] != $this->key_name ) return $field;

		if ( ! count( array_column( $price_field['price_field_values'], 'max_value' ) ) ) return $field;

		$event_id = $processor['config']['id'];

		// filter field options
		$field['config']['option'] = array_reduce( $price_field['price_field_values'], function( $options, $price_field_value ) use ( $field, $event_id ) {

			$option = $field['config']['option'][$price_field_value['id']];

			$current_count = CRM_Event_BAO_Participant::priceSetOptionsCount( $event_id );

			// disable option based on max value count
			if ( array_key_exists( 'max_value', $price_field_value ) && $current_count[$price_field_value['id']] >= $price_field_value['max_value'] ) {
				$option['disabled'] = true;
				$option['label'] .= ' ' . __( '(Sold out!)', 'caldera-forms-civicrm' );
			}

			$options[$price_field_value['id']] = $option;

			return $options;
		}, [] );

		return $field;
	}

	/**
	 * Do event autidiscounts.
	 *
	 * @since 1.0
	 * @param array $field Field config
	 * @param array $form Form cofig
	 * @param string $processor_id Processor id
	 * @param array $price_field The price field and it's price field values
	 * @return array $field The filtered field
	 */
	public function do_event_autodiscounts( $field, $form, $processor_id, $price_field ) {

		if ( ! isset( $this->plugin->cividiscount ) ) return $field;

		// only for logged in/checksum users
		if ( ! $this->plugin->helper->current_contact_data_get() ) return $field;

		$processor_id = $this->parse_processor_id( $processor_id );

		// processor config
		$processor = $form['processors'][$processor_id];

		if ( $processor['type'] != $this->key_name ) return $field;

		$event_discount = $this->event_cividiscounts[$processor_id];

		if ( ! $event_discount ) return $field;

		$transient = $this->plugin->transient->get();

		$contact_link = 'cid_' . $processor['config']['contact_link'];
		$contact_id = property_exists( $transient->contacts, $contact_link ) && ! empty( $transient->contacts->$contact_link ) ? $transient->contacts->$contact_link : false;

		// does the contact meet the autodiscount criteria?
		if ( $contact_id )
			$is_autodiscount = $this->plugin->cividiscount->check_autodiscount( $event_discount['autodiscount'], $transient->contacts->$contact_link, $processor_id );

		// bail if not
		if ( ! $is_autodiscount ) return $field;

		$this->discounts_used[$field['ID']] = $event_discount;

		// filter field options
		$field['config']['option'] = array_reduce( $price_field['price_field_values'], function( $options, $price_field_value ) use ( $field, $event_discount ) {

			$option = $field['config']['option'][$price_field_value['id']];

			// do discounted option
			$options[$price_field_value['id']] = $this->plugin->cividiscount->do_discounted_option( $option, $field, $price_field_value, $event_discount );

			return $options;
		}, [] );

		return $field;
	}

	/**
	 * Filter price field options for discounted options (pricesets).
	 * 
	 * @since 1.0
	 * @param array $field The field structure
	 * @param array $form The form config
	 * @param array $price_field Price field and it's price_field_values
	 * @param string $current_filter The current filter 
	 * @return array $field The field structure 
	 */
	public function do_options_autodiscounts( $field, $form, $price_field, $current_filter ) {

		if ( ! isset( $this->plugin->cividiscount ) ) return $field;

		// only for logged in/checksum users
		if ( ! $this->plugin->helper->current_contact_data_get() ) return $field;

		if ( ! $this->price_field_option_refs ) return $field;

		if ( ! array_key_exists( $field['ID'], $this->price_field_option_refs ) ) return $field;

		array_map( function( $field_id, $options_refs ) use ( &$field, $form, $price_field, $current_filter ) {

			if ( $field_id != $field['ID'] ) return;

			$processor = $form['processors'][$options_refs['processor_id']];

			if ( $processor['type'] != $this->key_name ) return $field;

			$options_discount = $this->options_cividiscounts[$field['ID']];

			if ( ! $options_discount ) return $field;

			$transient = $this->plugin->transient->get();

			$contact_link = 'cid_' . $processor['config']['contact_link'];
			$contact_id = property_exists( $transient->contacts, $contact_link ) && ! empty( $transient->contacts->$contact_link ) ? $transient->contacts->$contact_link : false;
			
			if ( $contact_id )
				$is_autodiscount = $this->plugin->cividiscount->check_autodiscount( $options_discount['autodiscount'], $transient->contacts->$contact_link, $options_refs['processor_id'] );

			// bail if not
			if ( ! $is_autodiscount ) return $field;

			// filter field options
			$field['config']['option'] = array_reduce( $price_field['price_field_values'], function( $options, $price_field_value ) use ( $field, $options_discount ) {

				$option = $field['config']['option'][$price_field_value['id']];

				if ( in_array( $option['value'], $options_discount['pricesets'] ) ) {

					$this->discounts_used[$field['ID']] = $options_discount;

					// do discounted option
					$options[$price_field_value['id']] = $this->plugin->cividiscount->do_discounted_option( $option, $field, $price_field_value, $options_discount );
				} else {
					$options[$price_field_value['id']] = $option;
				}

				return $options;

			}, [] );

			return $field;

		}, array_keys( $this->price_field_option_refs ), $this->price_field_option_refs );

		return $field;

	}

	/**
	 * Do code event discounts.
	 *
	 * @since 1.0
	 * @param array $field Field config
	 * @param array $form Form cofig
	 * @param string $processor_id Processor id
	 * @param array $price_field The price field and it's price field values
	 * @return array $field The filtered field
	 */
	public function do_event_code_discounts( $field, $form, $processor_id, $price_field ) {

		if ( ! isset( $this->plugin->cividiscount ) ) return $field;

		$discount_fields = $this->plugin->cividiscount->get_discount_fields( $form );

		if ( ! $discount_fields ) return $field;

		$processor_id = $this->parse_processor_id( $processor_id );

		array_map( function( $discount_field_id, $discount_field ) use ( &$field, $form, $processor_id, $price_field ) {

			$code = Caldera_Forms::get_field_data( $discount_field_id, $form );

			if ( ! $code ) return;

			$discount = $this->plugin->cividiscount->get_by_code( $code );

			if ( ! $discount || ! isset( $discount['events'] ) ) return;

			if ( ! in_array( $this->event_ids[$processor_id], $discount['events'] ) ) return;

			$this->discounts_used[$field['ID']] = $discount;

			$field['config']['option'] = array_reduce( $price_field['price_field_values'], function( $options, $price_field_value ) use ( &$field, $discount ) {

				$option = $field['config']['option'][$price_field_value['id']];

				// do discounted option
				$options[$price_field_value['id']] = $this->plugin->cividiscount->do_discounted_option( $option, $field, $price_field_value, $discount );

				return $options;

			}, [] );

			return $field;

		}, array_keys( $discount_fields ), $discount_fields );

		return $field;

	}

	/**
	 * Do code discounts for options based cividiscounts.
	 * 
	 * @since 1.0
	 * @param array $field The field structure
	 * @param array $form The form config
	 * @param array $price_field Price field and it's price_field_values
	 * @param string $current_filter The current filter 
	 * @return array $field The field structure 
	 */
	public function do_options_code_discounts( $field, $form, $price_field, $current_filter ) {

		if ( ! isset( $this->plugin->cividiscount ) ) return $field;

		$discount_fields = $this->plugin->cividiscount->get_discount_fields( $form );

		if ( ! $discount_fields ) return $field;

		array_map( function( $discount_field ) use ( &$field, $form, $price_field ) {

			$code = Caldera_Forms::get_field_data( $discount_field['ID'], $form );

			if ( ! $code ) return;

			$discount = $this->plugin->cividiscount->get_by_code( $code );

			if ( ! $discount || ! isset( $discount['pricesets'] ) ) return;

			$field['config']['option'] = array_reduce( $price_field['price_field_values'], function( $options, $price_field_value ) use ( $field, $discount ) {

				$option = $field['config']['option'][$price_field_value['id']];

				if ( in_array( $option['value'], $discount['pricesets'] ) ) {

					$this->discounts_used[$field['ID']] = $discount;

					// do discounted option
					$options[$price_field_value['id']] = $this->plugin->cividiscount->do_discounted_option( $option, $field, $price_field_value, $discount );
				} else {
					$options[$price_field_value['id']] = $option;
				}

				return $options;

			}, [] );

		}, $discount_fields );

		return $field;

	}

	/**
	 * Render notices for paid events.
	 *
	 * @since 1.0
	 * @param array $field The field structure
	 * @param array $form The form config
	 * @param array $price_field Price field and it's price_field_values
	 * @param string $current_filter The current filter 
	 * @return array $field The field structure 
	 */
	public function render_notices_for_paid_events( $field, $form, $price_field ) {

		if ( ! $this->price_field_refs ) return $field;

		if ( ! array_search( $field['id'], $this->price_field_refs ) ) return $field;

		$processors = $this->plugin->helper->get_processor_by_type( $this->key_name, $form );

		array_map( function( $processor_id, $field_id ) use ( &$field, $form, $processors ) {

			if ( $field_id != $field['id'] ) return;

			$processor_id = $this->parse_processor_id( $processor_id );

			// only paid events will have a price set/price field
			if ( ! $processors[$processor_id]['config']['is_monetary'] ) return;

			$notice = $this->get_notice( $processor_id, $form );

			if ( ! $notice ) return;

			// notice html
			$template_path = CF_CIVICRM_INTEGRATION_PATH . 'templates/notice.php';
			$html = $this->plugin->html->generate( $notice, $template_path );

			$field['label_after'] = $field['label_after'] . $html;

		}, array_keys( $this->price_field_refs ), $this->price_field_refs );

		return $field;
	}

	/**
	 * Render notices for paid events, at the top of the form.
	 *
	 * @since 1.o
	 * @param array $form The form config
	 * @return $form
	 */
	public function render_notices_for_non_paid_events( $form ) {

		// participant processors
		$processors = $this->plugin->helper->get_processor_by_type( $this->key_name, $form );

		if ( ! $processors ) return;

		array_map( function( $processor_id, $processor ) use ( $form ) {

			// only for non paid events
			if ( $processor['config']['is_monetary'] ) return;
			// render notice
			$this->get_notice( $processor_id, $form, $add_filter = true );

		}, array_keys( $processors ), $processors );

	}

	/**
	 * Get notice for participant processor.
	 *
	 * @since 1.0
	 * @param string $processor_id The processor id
	 * @param array $form The form config
	 * @param boolean $add_filter Wheather to add 'cfc_notices_to_render' filter
	 * @return array $notice Notice data array
	 */
	public function get_notice( $processor_id, $form, $add_filter = false ) {

		$processor = $form['processors'][$processor_id];
		$event = $this->events[$processor_id];
		$participant = $this->registrations[$processor_id];

		// notices filter
		$filter = 'cfc_notices_to_render';
		// cfc_notices_to_render filter callback
		$callback = function( $notices ) use ( $event, &$notice ) {
			$notices[] = $notice;
			return $notices;
		};

		// is registered
		if ( $participant && $participant['event_id'] == $event['id'] && $event['allow_same_participant_emails'] != 1 ) {
			$notice = [
				'type' => 'warning',
				'note' => sprintf( __( 'Oops. It looks like you are already registered for the event <strong>%1$s</strong>. If you want to change your registration, or you think that this is an error, please contact the site administrator.', 'caldera-forms-civicrm' ), $event['title'] ),
				'disabled' => true
			];

			if ( ! $add_filter ) return $notice;
			// render notices
			add_filter( $filter, $callback );
			return;
		}

		// registration start date
		if ( isset( $event['registration_start_date'] ) && date( 'Y-m-d H:i:s' ) <= $event['registration_start_date'] ) {
			$notice = [
				'type' => 'warning',
				'note' => sprintf( __( 'Registration for the event <strong>%s</strong> is not yet opened.', 'caldera-forms-civicrm' ), $event['title'] ),
				'disabled' => true
			];

			if ( ! $add_filter ) return $notice;
			// render notices
			add_filter( $filter, $callback );
			return;
		}

		// registration end date
		if ( isset( $event['registration_end_date'] ) && date( 'Y-m-d H:i:s' ) >= $event['registration_end_date']  ) {
			$notice = [
				'type' => 'warning',
				'note' => sprintf( __( 'Registration for the event <strong>%1$s</strong> was closed on %2$s.', 'caldera-forms-civicrm' ), $event['title'], date_format( date_create( $event['registration_end_date'] ), 'F d, Y H:i' ) ),
				'disabled' => true
			];

			if ( ! $add_filter ) return $notice;
			// render notices
			add_filter( $filter, $callback );
			return;
		}

		// is participant approval
		if ( $event['requires_approval'] ) {
			$notice = [
				'type' => 'warning',
				'note' => sprintf( __( '%s', 'caldera-forms-civicrm' ), $event['approval_req_text'] ),
				'disabled' => false
			];

			if ( ! $add_filter ) return $notice;
			// render notices
			add_filter( $filter, $callback );
			return;
		}

		// has waitlist and is full
		if ( $this->is_full( $event ) && $event['has_waitlist'] ) {
			$notice = [
				'type' => 'warning',
				'note' => sprintf( __( '%s', 'caldera-forms-civicrm' ), $event['waitlist_text'] ),
				'disabled' => false
			];

			if ( ! $add_filter ) return $notice;
			// render notices
			add_filter( $filter, $callback );
			return;
		}

		// event full
		if ( $this->is_full( $event ) && ! $event['has_waitlist'] ) {
			$notice = [
				'type' => 'warning',
				'note' => sprintf( __( '%s', 'caldera-forms-civicrm' ), $event['event_full_text'] ),
				'disabled' => true
			];

			if ( ! $add_filter ) return $notice;
			// render notices
			add_filter( $filter, $callback );
			return;
		}

	}

	/**
	 * Get event.
	 *
	 * @since 1.0
	 * @param int $id Event id
	 * @return array|boolean $event The event settings, or false
	 */
	public function get_event( $id ) {

		try {
			$event = civicrm_api3( 'Event', 'getsingle', [ 'id' => $id ] );
		} catch( CiviCRM_API3_Exception $e ) {
			$error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
			return [ 'note' => $error, 'type' => 'error' ];
		}

		if ( is_array( $event ) && ! $event['is_error'] ) {
			// get count
			$event['participant_count'] = CRM_Event_BAO_Event::getParticipantCount( $id );
			return $event;
		}

		return false;
	}

	/**
	 * Event is full.
	 *
	 * @since 1.0
	 * @param array $event The event config
	 * @return boolean True if full, false otherwise
	 */
	public function is_full( $event ) {
		if ( isset( $event['participant_count'] ) && isset( $event['max_participants'] ) )
			return $event['participant_count'] >= $event['max_participants'];
	}

	/**
	 * Get default status.
	 *
	 * @since 1.0
	 * @param array $event The event config
	 * @param array $config Processor config
	 * @return string $status The participant status
	 */
	public function default_status( $event, $config ) {

		if ( $config['status_id'] != 'default_status_id' ) return $config['status_id'];

		if ( $event['requires_approval'] ) return 'Awaiting approval';

		if ( $event['has_waitlist'] && $this->is_full( $event ) ) return 'On waitlist';

		return 'Registered';
	}

	/**
	 * Get contact event registrations.
	 *
	 * @since 1.0
	 * @param array $form The form config
	 * @return array $registrations The participant registrations
	 */
	public function get_participant_registrations( $event_ids, $form ) {

		// participant processors
		$processors = $this->plugin->helper->get_processor_by_type( $this->key_name, $form );
		if ( ! $processors ) return false;

		// cfc transient
		$transient = $this->plugin->transient->get();

		return array_map( function( $processor ) use ( $transient, $event_ids ) {

			if ( ! isset( $processor['runtimes'] ) ) return;

			$contact_link = 'cid_' . $processor['config']['contact_link'];

			if ( ! isset( $transient->contacts->{$contact_link} ) || empty( $transient->contacts->{$contact_link} ) ) return;

			try {
				$participant = civicrm_api3( 'Participant', 'get', [
					'sequential' => 1,
					'contact_id' => $transient->contacts->{$contact_link},
					'event_id' => [ 'IN' => array_values( $event_ids ) ]
				] );
			} catch ( CiviCRM_API3_Exception $e ) {

			}

			if ( ! $participant['count'] ) return;

			$registrations = array_filter( $participant['values'], function( $participant ) use ( $processor, $event_ids ) {
				return $participant['event_id'] == $event_ids[$processor['ID']];
			} );

			return array_pop( $registrations );

		}, $processors );
	}

	public function send_mail( $participant, $event, $order = false ) {

		if ( $order ) {

			$price_set = '';
			$line_items = array_reduce( $order['line_items'], function( $items, $item ) use ( &$price_set ) {

				$price_field = $this->plugin->helper->get_price_set_column_by_id( $item['price_field_id'], 'price_field' );

				$price_set = empty( $price_set ) ? $this->plugin->helper->get_price_set_column_by_id( $price_field['price_set_id'], 'price_set' ) : 	$price_set;

				$line_item = array_merge( $price_field['price_field_values'][$item['price_field_value_id']], $item );
				$line_item['field_title'] = $price_field['label'];

				$items[$price_field['price_set_id']][$item['price_field_value_id']] = $line_item;

				return $items;

			}, [] );

			$template = CRM_Core_Smarty::singleton();

			$template->assign( 'amount', $order['total_amount'] );
			$template->assign( 'totalAmount', $order['total_amount'] );
			$template->assign( 'currency', $order['currency'] );
			$template->assign( 'receive_date', $order['receive_date'] );
			$template->assign( 'trxn_id', $order['trxn_id'] );

			$address_fields = [ 'name', 'street_address', 'supplemental_address_1', 'supplemental_address_2', 'supplemental_address_3', 'city', 'state_province_id.abbreviation', 'postal_code', 'country_id.name' ];

			try {
				$billing_address = civicrm_api3( 'Address', 'get', [
					'sequential' => 1,
					'contact_id' => $participant['contact_id'],
					'location_type_id' => 'Billing',
					'return' => $address_fields
				] );
			} catch ( CiviCRM_API3_Exception $e ) {

			}

			if ( ! $billing_address['is_error'] && $billing_address['count'] ) {

				$address = $billing_address['values'][0];

				$billing_name = $address['name'] ? "{$address['name']}<br>" : '';
				$billing_name .= $address['street_address'] ? "{$address['street_address']}<br>" : '';
				$billing_name .= $address['supplemental_address_1'] ? "{$address['supplemental_address_1']}<br>" : '';
				$billing_name .= $address['supplemental_address_2'] ? "{$address['supplemental_address_2']}<br>" : '';
				$billing_name .= $address['supplemental_address_3'] ? "{$address['supplemental_address_3']}<br>" : '';
				$billing_name .= $address['city'] ? "{$address['city']}, {$address['state_province_id.abbreviation']} {$address['postal_code']}<br>" : '';
				$billing_name .= $address['country_id.name'] ? "{$address['country_id.name']}<br>" : '';

				$template->assign( 'billingName', $billing_name );

			}

			if ( isset( $order['card_type_id'] ) ) {
				$template->assign( 'contributeMode', 'direct' );
				$template->assign( 'credit_card_type', $order['credit_card_type'] );
				$template->assign( 'credit_card_number', $order['pan_truncation'] );
				$template->assign( 'credit_card_exp_date', $order['credit_card_exp_date'] );
			}

			if ( isset( $order['is_pay_later'] ) ) {
				$template->assign( 'is_pay_later', $order['is_pay_later'] );
			}

			$values['contributionId'] = $order['id'];
			$values['lineItem'] = $line_items;
			$values['fee'] = $price_set['price_fields'];
		}

		$values['isPrimary'] = 1;
		$values['event'] = $event;
		$values['participant'] = $participant;
		$values['location'] = CRM_Core_BAO_Location::getValues( [ 'entity_id' => $event['id'], 'entity_table' => 'civicrm_event' ] );
		$value['register_date'] = $participant['participant_register_date'];

		$sent = CRM_Event_BAO_Event::sendMail( $participant['contact_id'], $values, $participant['participant_id'] );

	}

	/**
	 * Add Participant to extend custom fields autopopulation/presets.
	 *
	 * @since 1.0
	 * @param array $extends The entites array
	 * @return array $extends The filtered entities array
	 */
	public function custom_fields_extend_participant( $extends ) {
		$extends[] = 'Participant';
		return $extends;
	}

	/**
	 * Parse processor id string containing '#'.
	 *
	 * @since 1.0
	 * @param string $processor_id The processor id
	 * @return string $processor_id The processor id
	 */
	public function parse_processor_id( $processor_id ) {
		return strpos( $processor_id, '#' ) ? substr( $processor_id, 0, strpos( $processor_id, '#' ) ) : $processor_id;
	}

	/**
	 * Checkes whether a participant is registered and same email address is allowed.
	 *
	 * @since 1.0
	 * @param bool $is_registered Participant is registered
	 * @param array $event The event settings
	 * @return bool $is_allowed
	 */
	public function is_registered_and_same_email_allowed( $is_registered, $event ) {
		return $is_registered && isset( $event['allow_same_participant_emails'] ) && $event['allow_same_participant_emails'];
	}

}
