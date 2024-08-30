<?php
declare(strict_types=1);
namespace WPShieldon;
use function call_user_func;
use function count;
use function is_array;
use function is_callable;

class WPSO_Setting_API {
	protected array $setting_sections = [];
	protected array $settings_fields  = [];

	public function __construct() {
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ]);
	}

	public function admin_enqueue_scripts() {
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_media();
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'setting-api', SHIELDON_PLUGIN_URL . 'includes/assets/js/shieldon-setting-api.js', [ 'jquery' ], SHIELDON_PLUGIN_VERSION, true );
	}

	public function set_sections( array $sections ) {
		$this->setting_sections = $sections;
	}

	public function add_section( array $section ) {
		$this->setting_sections[] = $section;
	}

	public function set_fields( array $fields ) {
		$this->settings_fields = $fields;
	}

	/**
	 * Add a single field
	 * @param string $section Section name.
	 * @param array  $field Field array.
	 */
	public function add_field( string $section, $field ) {
		$defaults                            = [
			'name'  => '',
			'label' => '',
			'desc'  => '',
			'type'  => 'text',
		];
		$arg                                 = wp_parse_args( $field, $defaults );
		$this->settings_fields[ $section ][] = $arg;
	}

	/**
	 * Initialize and registers the settings sections and fileds to WordPress
	 * Usually this should be called at `admin_init` hook.
	 * This function gets the initiated settings sections and fields. Then
	 * registers them to WordPress and ready for use.
	 */
	public function admin_init() {
		// register settings sections
		foreach ( $this->setting_sections as $section ) {
			if ( get_option( $section['id'] ) === false ) {
				add_option( $section['id'] );
			}
			if ( isset( $section['desc'] ) && ! empty( $section['desc'] ) ) {
				$section['desc'] = '<div class="inside">' . $section['desc'] . '</div>';
				$callback        = function () use ( $section ) {
					echo str_replace( '"', '\"', $section['desc'] );
				};
			} elseif ( isset( $section['callback'] ) ) {
				$callback = $section['callback'];
			} else {
				$callback = null;
			}
			$page_title = '<span class="g-tab-title">' . $section['title'] . '</span>';
			add_settings_section( $section['id'] . '_0', $page_title, $callback, $section['id'] );
		}
		// register settings fields
		foreach ( $this->settings_fields as $section => $field ) {
			$i                  = 0;
			$next_section_group = $section . '_' . $i;
			foreach ( $field as $option ) {
				++$i;
				$name     = $option['name'] ?? 'No name';
				$type     = $option['type'] ?? 'text';
				$label    = $option['label'] ?? '';
				$callback = $option['callback'] ?? [ $this, 'callback_' . $type ];
				if ( isset( $option['section_title'] ) && $option['section_title'] === true ) {
					// Create a section in same page if $name is empty.
					$next_section_group = $section . '_' . $i;
					$location_id        = $option['location_id'] ?? '';
					$section_title      = '<span class="g-section-title" id="' . $location_id . '">' . $option['label'] . '</span>';
					if ( ! empty( $option['desc'] ) ) {
						$section_title = '<span class="g-section-title" id="' . $location_id . '">' . $option['label'] . '<span class="g-section-title-desc">' . $option['desc'] . '</span></span>';
					}
					add_settings_section( $next_section_group, $section_title, '', $section );
				} else {
					$args = [
						'id'                => $name,
						'class'             => $option['class'] ?? $name,
						'label_for'         => "{$section}[{$name}]",
						'desc'              => $option['desc'] ?? '',
						'name'              => $label,
						'section'           => $section,
						'size'              => $option['size'] ?? null,
						'options'           => $option['options'] ?? '',
						'std'               => $option['default'] ?? '',
						'sanitize_callback' => $option['sanitize_callback'] ?? '',
						'type'              => $type,
						'placeholder'       => $option['placeholder'] ?? '',
						'min'               => $option['min'] ?? '',
						'max'               => $option['max'] ?? '',
						'step'              => $option['step'] ?? '',
						'location_id'       => $option['location_id'] ?? '',
						'parent'            => $option['parent'] ?? '',
						'has_child'         => $option['has_child'] ?? '',
					];
					add_settings_field( "{$section}[{$name}]", $label, $callback, $section, $next_section_group, $args );
				}
			}
		}
		// creates our settings in the options table
		foreach ( $this->setting_sections as $section ) {
			register_setting( $section['id'], $section['id'], [ $this, 'sanitize_options' ]);
		}
	}

	/**
	 * Displays a url field for a settings field
	 * @param array $args settings field args
	 */
	public function callback_url( array $args ) {
		$this->callback_text( $args );
	}

	/**
	 * Displays a text field for a settings field
	 * @param array $args settings field args
	 */
	public function callback_text( array $args ) {
		$value       = esc_attr( $this->get_option( $args['id'], $args['section'], $args['std'] ) );
		$size        = $args['size'] ?? 'regular';
		$type        = $args['type'] ?? 'text';
		$placeholder = empty( $args['placeholder'] ) ? '' : ' placeholder="' . $args['placeholder'] . '"';
		$html        = sprintf( '<input type="%1$s" class="%2$s-text" id="%3$s[%4$s]" name="%3$s[%4$s]" value="%5$s"%6$s/>', $type, $size, $args['section'], $args['id'], $value, $placeholder );
		$html       .= $this->get_field_description( $args );
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Get the value of a settings field
	 * @param string $option Settings field name
	 * @param string $section The section name this field belongs to
	 * @param string $default Default text if it's not found
	 */
	public function get_option( string $option, string $section, string $default = '' ): string {
		$options = get_option( $section );
		return $options[ $option ] ?? $default;
	}

	/**
	 * Get field description for display
	 * @param array $args settings field args
	 */
	public function get_field_description( $args ): string {
		if ( ! empty( $args['desc'] ) ) {
			$desc = sprintf( '<p class="description">%s</p>', $args['desc'] );
		} else {
			$desc = '';
		}
		return $desc;
	}

	/**
	 * Displays a number field for a settings field
	 * @param array $args settings field args
	 */
	public function callback_number( array $args ) {
		$value       = esc_attr( $this->get_option( $args['id'], $args['section'], $args['std'] ) );
		$size        = $args['size'] ?? 'regular';
		$type        = $args['type'] ?? 'number';
		$placeholder = empty( $args['placeholder'] ) ? '' : ' placeholder="' . $args['placeholder'] . '"';
		$min         = ( $args['min'] === '' ) ? '' : ' min="' . $args['min'] . '"';
		$max         = ( $args['max'] === '' ) ? '' : ' max="' . $args['max'] . '"';
		$step        = ( $args['step'] === '' ) ? '' : ' step="' . $args['step'] . '"';
		$html        = sprintf( '<input type="%1$s" class="%2$s-number" id="%3$s[%4$s]" name="%3$s[%4$s]" value="%5$s"%6$s%7$s%8$s%9$s/>', $type, $size, $args['section'], $args['id'], $value, $placeholder, $min, $max, $step );
		$html       .= $this->get_field_description( $args );
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Displays a checkbox for a settings field
	 * @param array $args settings field args
	 */
	public function callback_checkbox( array $args ) {
		$value = esc_attr( $this->get_option( $args['id'], $args['section'], $args['std'] ) );
		$html  = '<fieldset>';
		$html .= sprintf( '<label for="wpuf-%1$s-%2$s">', $args['section'], $args['id'] );
		$html .= sprintf( '<input type="hidden" name="%1$s[%2$s]" value="off" />', $args['section'], $args['id'] );
		$html .= sprintf( '<input type="checkbox" class="checkbox" id="wpuf-%1$s-%2$s" name="%1$s[%2$s]" value="on" %3$s />', $args['section'], $args['id'], checked( $value, 'on', false ) );
		$html .= sprintf( '%1$s</label>', $args['desc'] );
		$html .= '</fieldset>';
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Displays a toggle for a settings field
	 * @param array $args settings field args
	 */
	public function callback_toggle( array $args ) {
		$value     = esc_attr( $this->get_option( $args['id'], $args['section'], $args['std'] ) );
		$location  = $args['location_id'] ?? '';
		$has_child = isset( $args['has_child'] ) ? 'has-child' : '';
		$size      = $args['size'] ?? '';
		$html      = sprintf( '<div class="wpmd setting-toggle %4$s %5$s" data-location="%1$s" data-target="%2$s[%3$s]" data-setting="%3$s">', $location, $args['section'], $args['id'], $has_child, $size );
		$html     .= sprintf( '<input type="hidden" name="%1$s[%2$s]" value="no" />', $args['section'], $args['id'] );
		$html     .= sprintf( '<input type="checkbox" class="checkbox" id="wpuf-%1$s-%2$s" name="%1$s[%2$s]" value="yes" %3$s />', $args['section'], $args['id'], checked( $value, 'yes', false ) );
		$html     .= sprintf( '<label for="wpuf-%1$s-%2$s">', $args['section'], $args['id'] );
		$html     .= sprintf( '%1$s</label>', 'toggle' );
		$html     .= '</div>';
		$html     .= $this->get_field_description( $args );
		$html     .= '</fieldset>';
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Displays a multicheckbox for a settings field
	 * @param array $args settings field args
	 */
	public function callback_multicheck( array $args ) {
		$value        = $this->get_option( $args['id'], $args['section'], $args['std'] );
		$html         = '<fieldset>';
		$html        .= sprintf( '<input type="hidden" name="%1$s[%2$s]" value="" />', $args['section'], $args['id'] );
		$option_count = count( $args['options'] );
		foreach ( $args['options'] as $key => $label ) {
			$checked = $value[ $key ] ?? '0';
			if ( $option_count < 5 ) {
				$html .= '<div>';
			} else {
				$html .= '<div style="display: inline-block; margin-right: 15px;">';
			}
			$html .= sprintf( '<label for="wpuf-%1$s-%2$s-%3$s">', $args['section'], $args['id'], $key );
			$html .= sprintf( '<input type="checkbox" class="checkbox" id="wpuf-%1$s-%2$s-%3$s" name="%1$s[%2$s][%3$s]" value="%3$s" %4$s />', $args['section'], $args['id'], $key, checked( $checked, $key, false ) );
			$html .= sprintf( '%1$s</label>', $label );
			$html .= '</div>';
		}
		$html .= $this->get_field_description( $args );
		$html .= '</fieldset>';
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Displays a radio button for a settings field
	 * @param array $args settings field args
	 */
	public function callback_radio( array $args ) {
		$value = $this->get_option( $args['id'], $args['section'], $args['std'] );
		$html  = '<fieldset>';
		foreach ( $args['options'] as $key => $label ) {
			$html .= sprintf( '<label for="wpuf-%1$s-%2$s-%3$s">', $args['section'], $args['id'], $key );
			$html .= sprintf( '<input type="radio" class="radio" id="wpuf-%1$s-%2$s-%3$s" name="%1$s[%2$s]" value="%3$s" %4$s />', $args['section'], $args['id'], $key, checked( $value, $key, false ) );
			$html .= sprintf( '%1$s</label><br>', $label );
		}
		$html .= $this->get_field_description( $args );
		$html .= '</fieldset>';
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Displays a selectbox for a settings field
	 * @param array $args settings field args
	 */
	public function callback_select( array $args ) {
		$value = esc_attr( $this->get_option( $args['id'], $args['section'], $args['std'] ) );
		$size  = $args['size'] ?? 'regular';
		$html  = sprintf( '<select class="%1$s" name="%2$s[%3$s]" id="%2$s[%3$s]">', $size, $args['section'], $args['id'] );
		foreach ( $args['options'] as $key => $label ) {
			$html .= sprintf( '<option value="%s"%s>%s</option>', $key, selected( $value, $key, false ), $label );
		}
		$html .= '</select>';
		$html .= $this->get_field_description( $args );
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Displays a textarea for a settings field
	 * @param array $args settings field args
	 */
	public function callback_textarea( array $args ) {
		$value       = esc_textarea( $this->get_option( $args['id'], $args['section'], $args['std'] ) );
		$size        = $args['size'] ?? 'regular';
		$placeholder = empty( $args['placeholder'] ) ? '' : ' placeholder="' . $args['placeholder'] . '"';
		$html        = sprintf( '<textarea rows="5" cols="55" class="%1$s-text" id="%2$s[%3$s]" name="%2$s[%3$s]"%4$s>%5$s</textarea>', $size, $args['section'], $args['id'], $placeholder, $value );
		$html       .= $this->get_field_description( $args );
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Displays the html for a settings field
	 * @param array $args settings field args
	 */
	public function callback_html( array $args ) {
		$html = $args['desc'];
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Displays a rich text textarea for a settings field
	 * @param array $args settings field args
	 */
	public function callback_wysiwyg( array $args ) {
		$value = $this->get_option( $args['id'], $args['section'], $args['std'] );
		$size  = $args['size'] ?? '500px';
		if ( ! empty( $args['parent'] ) ) {
			echo '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">';
		}
		echo '<div style="max-width: ' . $size . ';">';
		$editor_settings = [
			'teeny'         => true,
			'textarea_name' => $args['section'] . '[' . $args['id'] . ']',
			'textarea_rows' => 10,
		];
		if ( isset( $args['options'] ) && is_array( $args['options'] ) ) {
			$editor_settings = array_merge( $editor_settings, $args['options'] );
		}
		wp_editor( $value, $args['section'] . '-' . $args['id'], $editor_settings );
		echo '</div>';
		echo $this->get_field_description( $args );
		if ( ! empty( $args['parent'] ) ) {
			echo '</div>';
		}
	}

	/**
	 * Displays a file upload field for a settings field
	 * @param array $args settings field args
	 */
	public function callback_file( array $args ) {
		$value = esc_attr( $this->get_option( $args['id'], $args['section'], $args['std'] ) );
		$size  = $args['size'] ?? 'regular';
		$id    = $args['section'] . '[' . $args['id'] . ']';
		$label = $args['options']['button_label'] ?? __( 'Choose File' );
		$html  = sprintf( '<input type="text" class="%1$s-text wpsa-url" id="%2$s[%3$s]" name="%2$s[%3$s]" value="%4$s"/>', $size, $args['section'], $args['id'], $value );
		$html .= '<input type="button" class="button wpsa-browse" value="' . $label . '" />';
		$html .= $this->get_field_description( $args );
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Displays a password field for a settings field
	 * @param array $args settings field args
	 */
	public function callback_password( array $args ) {
		$value = esc_attr( $this->get_option( $args['id'], $args['section'], $args['std'] ) );
		$size  = $args['size'] ?? 'regular';
		$html  = sprintf( '<input type="password" class="%1$s-text" id="%2$s[%3$s]" name="%2$s[%3$s]" value="%4$s"/>', $size, $args['section'], $args['id'], $value );
		$html .= $this->get_field_description( $args );
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Displays a color picker field for a settings field
	 * @param array $args settings field args
	 */
	public function callback_color( array $args ) {
		$value = esc_attr( $this->get_option( $args['id'], $args['section'], $args['std'] ) );
		$size  = $args['size'] ?? 'regular';
		$html  = sprintf( '<input type="text" class="%1$s-text wp-color-picker-field" id="%2$s[%3$s]" name="%2$s[%3$s]" value="%4$s" data-default-color="%5$s" />', $size, $args['section'], $args['id'], $value, $args['std'] );
		$html .= $this->get_field_description( $args );
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Displays a select box for creating the pages select box
	 * @param array $args settings field args
	 */
	public function callback_pages( array $args ) {
		$dropdown_args = [
			'selected' => esc_attr( $this->get_option( $args['id'], $args['section'], $args['std'] ) ),
			'name'     => $args['section'] . '[' . $args['id'] . ']',
			'id'       => $args['section'] . '[' . $args['id'] . ']',
			'echo'     => 0,
		];
		$html          = wp_dropdown_pages( $dropdown_args );
		if ( ! empty( $args['parent'] ) ) {
			$html = '<div class="setting-has-parent" data-parent="' . $args['parent'] . '">' . $html . '</div>';
		}
		echo $html;
	}

	/**
	 * Sanitize callback for Settings API
	 * @param array $options The options to be sanitized.
	 */
	public function sanitize_options( array $options ): array {
		if ( ! $options ) {
			return $options;
		}
		foreach ( $options as $option_slug => $option_value ) {
			$sanitize_callback = $this->get_sanitize_callback( $option_slug );
			// If callback is set, call it
			if ( $sanitize_callback ) {
				$options[ $option_slug ] = call_user_func( $sanitize_callback, $option_value );
			}
		}
		return $options;
	}

	/**
	 * Get sanitization callback for given option slug
	 * @param string $slug The pption slug
	 * @return callable|false string or bool false
	 */
	public function get_sanitize_callback( string $slug = '' ) {
		if ( empty( $slug ) ) {
			return false;
		}
		// Iterate over registered fields and see if we can find proper callback
		foreach ( $this->settings_fields as $section => $options ) {
			foreach ( $options as $option ) {
				if ( ! isset( $option['name'] ) || $option['name'] !== $slug ) {
					continue;
				}
				// Return the callback name
				return isset( $option['sanitize_callback'] ) && is_callable( $option['sanitize_callback'] ) ? $option['sanitize_callback'] : false;
			}
		}
		return false;
	}

	/**
	 * Show navigations as tab
	 * Shows all the settings section labels as tab
	 */
	public function show_navigation() {
		$html  = '<h2 class="nav-tab-wrapper">';
		$count = count( $this->setting_sections );
		// don't show the navigation if only one section exists
		if ( $count === 1 ) {
			return;
		}
		foreach ( $this->setting_sections as $tab ) {
			$html .= sprintf( '<a href="#%1$s" class="nav-tab" id="%1$s-tab">%2$s</a>', $tab['id'], $tab['title'] );
		}
		$html .= '</h2>';
		echo $html;
	}

	/**
	 * Show the section settings forms
	 * This function displays every sections in a different form
	 */
	public function show_forms() {
		?>
		<div class="metabox-holder">
			<?php
			foreach ( $this->setting_sections as $form ) {
                //error_log(var_export($form, true));
				?>
				<div id="<?php echo $form['id']; ?>" class="group" style="display: none;">
					<form method="post" action="options.php">
						<?php
						do_action( 'wsa_form_top_' . $form['id'], $form );
						settings_fields( $form['id'] );
						do_settings_sections( $form['id'] );
						do_action( 'wsa_form_bottom_' . $form['id'], $form );
						if ( isset( $this->settings_fields[ $form['id'] ] ) ) :
							?>
							<div style="border-top: 1px #cccccc solid; margin-top: 20px;">
								<?php
								submit_button();
								?>
							</div>
							<?php
						endif;
						?>
					</form>
				</div>
				<?php
			}
			?>
		</div>
		<?php
		$this->script();
	}

	/**
	 * Tabbable JavaScript codes & Initiate Color Picker
	 * This code uses localstorage for displaying active tabs
	 */
	public function script() {
		?>
		<script>
			jQuery(document).ready(function ($) {
				//Initiate Color Picker
				$('.wp-color-picker-field').wpColorPicker();
				// Switches option sections
				$('.group').hide();
				var activetab = '';
				if (typeof (localStorage) != 'undefined') {
					activetab = localStorage.getItem("activetab");
				}
				//if url has section id as hash then set it as active or override the current local storage value
				if (window.location.hash) {
					activetab = window.location.hash;
					if (typeof (localStorage) != 'undefined') {
						localStorage.setItem("activetab", activetab);
					}
				}
				if (activetab != '' && $(activetab).length) {
					$(activetab).fadeIn();
				} else {
					$('.group:first').fadeIn();
				}
				$('.group .collapsed').each(function () {
					$(this).find('input:checked').parent().parent().parent().nextAll().each(
						function () {
							if ($(this).hasClass('last')) {
								$(this).removeClass('hidden');
								return false;
							}
							$(this).filter('.hidden').removeClass('hidden');
						});
				});
				if (activetab != '' && $(activetab + '-tab').length) {
					$(activetab + '-tab').addClass('nav-tab-active');
				} else {
					$('.nav-tab-wrapper a:first').addClass('nav-tab-active');
				}
				$('.nav-tab-wrapper a').click(function (evt) {
					$('.nav-tab-wrapper a').removeClass('nav-tab-active');
					$(this).addClass('nav-tab-active').blur();
					var clicked_group = $(this).attr('href');
					if (typeof (localStorage) != 'undefined') {
						localStorage.setItem("activetab", $(this).attr('href'));
					}
					$('.group').hide();
					$(clicked_group).fadeIn();
					evt.preventDefault();
				});
				$('.wpsa-browse').on('click', function (event) {
					event.preventDefault();
					var self = $(this);
					// Create the media frame.
					var file_frame = wp.media.frames.file_frame = wp.media({
						title: self.data('uploader_title'),
						button: {
							text: self.data('uploader_button_text'),
						},
						multiple: false
					});
					file_frame.on('select', function () {
						attachment = file_frame.state().get('selection').first().toJSON();
						self.prev('.wpsa-url').val(attachment.url).change();
					});
					// Finally, open the modal
					file_frame.open();
				});
			});
		</script>
		<?php
	}
}
