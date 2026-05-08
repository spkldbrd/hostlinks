<?php
/**
 * Shared Hostlinks top actions bar (calendar / reports / certificate hub).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hostlinks_Toolbar {

	/**
	 * Month options for the reports-style date range control (keys = months back).
	 *
	 * @return array<int,string>
	 */
	public static function reports_month_options(): array {
		return array(
			6  => '6 Months',
			12 => '1 Year',
			24 => '2 Years',
			36 => '3 Years',
			60 => '5 Years',
		);
	}

	/**
	 * Parse $_GET the same way as the Reports shortcode for range filtering.
	 *
	 * @return array{range_mode:string,months_back:int,date_from:string,date_to:string}
	 */
	public static function parse_reports_range_from_request(): array {
		$months_options = self::reports_month_options();

		$range_mode  = 'months';
		$months_back = 12;
		$date_from   = '';
		$date_to     = '';

		if ( isset( $_GET['range'] ) && $_GET['range'] === 'current_year' ) {
			$range_mode = 'current_year';
		} elseif ( isset( $_GET['range'] ) && $_GET['range'] === 'custom'
			&& ! empty( $_GET['from'] ) && ! empty( $_GET['to'] ) ) {
			$range_mode = 'custom';
			$date_from  = sanitize_text_field( wp_unslash( $_GET['from'] ) );
			$date_to    = sanitize_text_field( wp_unslash( $_GET['to'] ) );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from )
				|| ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
				$range_mode = 'months';
			}
		} elseif ( isset( $_GET['months'] ) && isset( $months_options[ (int) $_GET['months'] ] ) ) {
			$months_back = (int) $_GET['months'];
		}

		return array(
			'range_mode'  => $range_mode,
			'months_back' => $months_back,
			'date_from'   => $date_from,
			'date_to'     => $date_to,
		);
	}

	public static function last_updated_display(): string {
		$_upd_raw = get_option( 'last_data_updation', '' );
		$_upd_dt  = $_upd_raw ? DateTime::createFromFormat( 'Y-m-d', $_upd_raw ) : null;
		return $_upd_dt ? $_upd_dt->format( 'm/d' ) : ( new DateTime() )->format( 'm/d' );
	}

	/**
	 * Marketing Ops, Certificates, and + Event visibility (matches Reports/Calendar rules).
	 *
	 * @param 'eventlisto'|'hostlinks_reports' $shortcode_for_all Which shortcode gates "all" mode.
	 * @return array{
	 *   show_mktops_btn:bool,
	 *   mktops_url:string,
	 *   show_cert_btn:bool,
	 *   cert_url:string,
	 *   show_add_event_btn:bool,
	 *   event_request_url:string
	 * }
	 */
	public static function resolve_hub_button_visibility( string $shortcode_for_all ): array {
		$can_all = Hostlinks_Access::can_view_shortcode( $shortcode_for_all );

		$mktops_btn_mode = get_option( 'hostlinks_mktops_btn', 'disabled' );
		$mktops_url      = ( $mktops_btn_mode !== 'disabled' ) ? Hostlinks_Page_URLs::get_mktops_hub() : '';
		$show_mktops_btn = false;
		if ( $mktops_url ) {
			if ( $mktops_btn_mode === 'admin' && current_user_can( 'manage_options' ) ) {
				$show_mktops_btn = true;
			} elseif ( $mktops_btn_mode === 'admin_plus_mgr' ) {
				if ( current_user_can( 'manage_options' ) ) {
					$show_mktops_btn = true;
				} elseif ( class_exists( 'HMO_Access_Service' ) && HMO_Access_Service::current_user_is_marketing_admin() ) {
					$show_mktops_btn = true;
				}
			} elseif ( $mktops_btn_mode === 'all' && $can_all ) {
				$show_mktops_btn = true;
			}
		}

		$cert_btn_mode = get_option( 'hostlinks_cert_btn', 'disabled' );
		$cert_url      = ( $cert_btn_mode !== 'disabled' ) ? Hostlinks_Page_URLs::get_certificate_hub() : '';
		$show_cert_btn = false;
		if ( $cert_url ) {
			if ( $cert_btn_mode === 'admin' && current_user_can( 'manage_options' ) ) {
				$show_cert_btn = true;
			} elseif ( $cert_btn_mode === 'custom' ) {
				$cert_btn_users = get_option( 'hostlinks_cert_btn_users', array() );
				if ( current_user_can( 'manage_options' ) || in_array( get_current_user_id(), array_map( 'intval', (array) $cert_btn_users ), true ) ) {
					$show_cert_btn = true;
				}
			} elseif ( $cert_btn_mode === 'all' && $can_all ) {
				$show_cert_btn = true;
			}
		}

		$add_event_btn_mode = get_option( 'hostlinks_add_event_btn', 'disabled' );
		$event_request_url  = ( $add_event_btn_mode !== 'disabled' ) ? Hostlinks_Page_URLs::get_event_request_form() : '';
		$show_add_event_btn = false;
		if ( $event_request_url ) {
			if ( $add_event_btn_mode === 'admin' && current_user_can( 'manage_options' ) ) {
				$show_add_event_btn = true;
			} elseif ( $add_event_btn_mode === 'custom' ) {
				$custom_btn_users = get_option( 'hostlinks_add_event_btn_users', array() );
				if ( current_user_can( 'manage_options' ) || in_array( get_current_user_id(), array_map( 'intval', (array) $custom_btn_users ), true ) ) {
					$show_add_event_btn = true;
				}
			} elseif ( $add_event_btn_mode === 'all' && $can_all ) {
				$show_add_event_btn = true;
			}
		}

		return array(
			'show_mktops_btn'    => $show_mktops_btn,
			'mktops_url'         => (string) $mktops_url,
			'show_cert_btn'      => $show_cert_btn,
			'cert_url'           => (string) $cert_url,
			'show_add_event_btn' => $show_add_event_btn,
			'event_request_url'  => (string) $event_request_url,
		);
	}

	/**
	 * Reports-style bar: Upcoming / Past / range / Reports · Certificates · Marketing Ops · + Event.
	 *
	 * @param 'reports'|'certificates'         $active              Which link shows as active.
	 * @param 'eventlisto'|'hostlinks_reports' $shortcode_for_all  Permission context for "all" toolbar modes.
	 */
	public static function render_reports_style_actions_bar( string $active, string $shortcode_for_all ): void {
		$months_options = self::reports_month_options();
		$range          = self::parse_reports_range_from_request();
		$range_mode     = $range['range_mode'];
		$months_back    = $range['months_back'];
		$date_from      = $range['date_from'];
		$date_to        = $range['date_to'];

		$upcoming_url    = Hostlinks_Page_URLs::get_upcoming();
		$past_events_url = Hostlinks_Page_URLs::get_past_events();
		$reports_url     = Hostlinks_Page_URLs::get_reports();

		$hubs    = self::resolve_hub_button_visibility( $shortcode_for_all );
		$updated = self::last_updated_display();
		?>
	<div class="hostlinks-actions">
		<a href="<?php echo esc_url( $upcoming_url ); ?>" class="hostlinks-btn">Upcoming Events</a>
		<a href="<?php echo esc_url( $past_events_url ); ?>" class="hostlinks-btn">Past Events</a>

		<select id="hl-reports-range" class="hostlinks-year-filter" aria-label="Date range" style="margin-left:auto;">
			<?php foreach ( $months_options as $val => $lbl ) : ?>
			<option value="months:<?php echo (int) $val; ?>"
				<?php selected( $range_mode === 'months' && $months_back === $val ); ?>>
				<?php echo esc_html( $lbl ); ?>
			</option>
			<?php endforeach; ?>
			<option value="current_year" <?php selected( $range_mode, 'current_year' ); ?>>Current Year</option>
			<option value="custom"       <?php selected( $range_mode, 'custom' ); ?>>Custom Range…</option>
		</select>

		<span id="hl-custom-range"
			style="display:<?php echo $range_mode === 'custom' ? 'inline-flex' : 'none'; ?>;align-items:center;gap:6px;margin-left:6px;">
			<input type="date" id="hl-from" value="<?php echo esc_attr( $date_from ); ?>"
				style="padding:4px 6px;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;" />
			<span style="color:#666;">to</span>
			<input type="date" id="hl-to" value="<?php echo esc_attr( $date_to ); ?>"
				style="padding:4px 6px;border:1px solid #ccc;border-radius:4px;font-size:0.85rem;" />
			<button type="button" id="hl-custom-go"
				style="padding:4px 10px;background:#0da2e7;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:0.85rem;">Go</button>
		</span>

		<?php if ( $reports_url ) : ?>
		<a href="<?php echo esc_url( $reports_url ); ?>" class="hostlinks-btn<?php echo $active === 'reports' ? ' hostlinks-btn--active' : ''; ?>">&#x1F4CA; Reports</a>
		<?php endif; ?>
		<?php if ( $hubs['show_cert_btn'] ) : ?>
		<a href="<?php echo esc_url( $hubs['cert_url'] ); ?>" class="hostlinks-btn hostlinks-btn--cert<?php echo $active === 'certificates' ? ' hostlinks-btn--active' : ''; ?>">&#x1F393; Certificates</a>
		<?php endif; ?>
		<?php if ( $hubs['show_mktops_btn'] ) : ?>
		<a href="<?php echo esc_url( $hubs['mktops_url'] ); ?>" class="hostlinks-btn hostlinks-btn--mktops">&#x1F4CB; Marketing Ops</a>
		<?php endif; ?>
		<?php if ( $hubs['show_add_event_btn'] ) : ?>
		<a href="<?php echo esc_url( $hubs['event_request_url'] ); ?>" class="hostlinks-btn hostlinks-btn--add-event">&#x2B; Event</a>
		<?php endif; ?>
		<?php if ( $reports_url ) : ?>
		<script>
		(function(){
			var sel    = document.getElementById('hl-reports-range');
			var custom = document.getElementById('hl-custom-range');
			var fromEl = document.getElementById('hl-from');
			var toEl   = document.getElementById('hl-to');
			var goBtn  = document.getElementById('hl-custom-go');
			var base   = <?php echo wp_json_encode( $reports_url ); ?>;
			if (!sel || !custom || !fromEl || !toEl || !goBtn || !base) return;
			sel.addEventListener('change', function() {
				var v = this.value;
				if (v === 'custom') {
					custom.style.display = 'inline-flex';
				} else if (v === 'current_year') {
					custom.style.display = 'none';
					window.location.href = base + (base.indexOf('?') === -1 ? '?' : '&') + 'range=current_year';
				} else {
					custom.style.display = 'none';
					var months = v.replace('months:', '');
					window.location.href = base + (base.indexOf('?') === -1 ? '?' : '&') + 'months=' + months;
				}
			});
			goBtn.addEventListener('click', function() {
				var from = fromEl.value;
				var to   = toEl.value;
				if (!from || !to) { alert('Please select both a start and end date.'); return; }
				if (from > to)    { alert('Start date must be before end date.'); return; }
				var sep = base.indexOf('?') === -1 ? '?' : '&';
				window.location.href = base + sep + 'range=custom&from=' + from + '&to=' + to;
			});
		})();
		</script>
		<?php endif; ?>
		<span class="hostlinks-updated">Updated: <?php echo esc_html( $updated ); ?></span>
	</div>
		<?php
	}
}
