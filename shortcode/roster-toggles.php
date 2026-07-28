	<div id="hl-roster-col-toggles" style="display:none;justify-content:flex-start;align-items:center;gap:12px;font-size:13px;color:#555;padding-top:6px;flex-wrap:wrap;">
		<span>Show columns:</span>
		<?php
		$toggle_labels = array(
			'participant'       => 'Participant',
			'email'             => 'Email',
			'status'            => 'Invitee Status',
			'work_phone'        => 'Work Phone',
			'mobile_phone'      => 'Mobile Phone',
			'amount_ordered'    => 'Amount Ordered',
			'amount_paid'       => 'Amount Paid',
			'discounts_applied' => 'Discounts Applied',
			'balance_due'       => 'Amount Due',
			'payment_type'      => 'Payment Type',
			'work_city'         => 'Work City',
			'work_state'        => 'Work State',
			'discount_code'     => 'Discount Code',
		);
		foreach ( $toggle_labels as $slug => $label ) :
			$id = 'hl-fe-' . str_replace( '_', '-', $slug );
		?>
		<label id="<?php echo esc_attr( $id ); ?>-wrap" style="cursor:pointer;display:<?php echo ( $slug === 'participant' ) ? 'none' : 'flex'; ?>;align-items:center;gap:4px;">
			<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" data-col="<?php echo esc_attr( $slug ); ?>" style="width:14px;height:14px;">
			<?php echo esc_html( $label ); ?>
		</label>
		<?php endforeach; ?>
		<em style="color:#aaa;font-size:11px;">(staff only — hide before printing for public)</em>
	</div>
